<?php
/**
 * Created by PhpStorm.
 * User: singularityDev
 * Date: 16-Nov-18
 * Time: 12:04
 */

class UploadManager
{
    private $transferMetadata;
    private $fileStorage;
    private $uploadDirectory;
    private $overwriteExisting;
    private $currentTransferSession;
    private $finalFileMergingRetries; // NOTE: Number of retries for writing the final file from chunks before aborting
    private $renameAfterUpload;

    public function __construct(string $uploadDirectory)
    {
        if(session_status() == PHP_SESSION_NONE)
        {
            session_start();
        }

        $this->uploadDirectory = $uploadDirectory;
        $this->overwriteExisting = true;
        $this->fileStorage = [];
        $this->finalFileMergingRetries = 3;
        $this->renameAfterUpload = null;
    }

    /**
     * @param bool $state
     * @return $this
     */
    public function overwriteExistingFiles(bool $state)
    {
        $this->overwriteExisting = $state;

        return $this;
    }

    /**
     * Renames a file after upload is complete
     *
     * @param string $newName File name (without extension) to be applied
     * WARNING! This method should be used on single file uploads only!
     * Also this WILL overwrite existing files with the same name
     *
     * @return $this
     */
    public function renameAfterUpload(string $newName)
    {
        $this->renameAfterUpload = $newName;
        return $this;
    }

    /**
     * Sets the number of retries for writing the final file from chunks before aborting
     * @param int $retries
     * @return $this
     */
    public function setMergingFromChunksRetries(int $retries)
    {
        $this->finalFileMergingRetries = $retries;

        return $this;
    }

    /**
     * @param string $uploadDirectory
     * @return $this
     */
    public function setUploadDir(string $uploadDirectory)
    {
        $this->uploadDirectory = $uploadDirectory;

        return $this;
    }

    public function handleUploads()
    {
        if(!$this->extractTransferMetadata())
        {
            $this->sendTransferError();
        }

        if(!$this->transferMetadata['integrityCheck'])
        {
            $this->sendTransferError();// NOTE: Data may have been tampered with
        }

        $this->currentTransferSession = 'UploadManagerStorage_'.$this->transferMetadata['sessionName'].$this->transferMetadata['sessionId'];

        if(isset($_SESSION[$this->currentTransferSession]))
        {
            $this->fileStorage = $_SESSION[$this->currentTransferSession];
        }

        if($this->writeDataChunk())
        {
            if($this->transferMetadata['totalChunks'] > 1)
            {
                if(!isset($this->fileStorage[$this->transferMetadata['chunkId']]))
                {
                    $this->fileStorage[$this->transferMetadata['chunkId']] = 1;
                }

                if ($this->transferMetadata['totalChunks'] == count($this->fileStorage))
                {
                    unset($_SESSION[$this->currentTransferSession]);
                    $this->fileStorage = null;
                    if(!$this->writeFileFromChunks($this->finalFileMergingRetries))
                    {
                        $basePath = $this->uploadDirectory . DIRECTORY_SEPARATOR;
                        $totalChunks = $this->transferMetadata['totalChunks'];
                        for($dataChunkId = 0; $dataChunkId < $totalChunks; ++$dataChunkId)
                        {
                            $temporaryFile =  $basePath.$dataChunkId . '_' . $this->transferMetadata['sessionId'] . '.sgChunk';
                            if(file_exists($temporaryFile))
                            {
                                unlink($temporaryFile);
                            }
                        }

                        $this->sendTransferError('Failed to write file!');
                    }
                }
            }
            if($this->fileStorage)
            {
                $_SESSION[$this->currentTransferSession] = $this->fileStorage;
            }
            $this->sendTransferSuccess();
        }
        else
        {
            unset($this->fileStorage[$this->transferMetadata['chunkId']]);

            if($this->fileStorage)
            {
                $_SESSION[$this->currentTransferSession] = $this->fileStorage;
            }

            $this->sendTransferError('Failed to copy from temporary to destination!');
        }
    }

    private function writeDataChunk()
    {
        $inputStream = fopen('php://input', 'rb');

        if(!$inputStream)
        {
            return false;
        }

        if($this->transferMetadata['totalChunks'] > 1)
        {
            $fileName = $this->uploadDirectory . DIRECTORY_SEPARATOR . $this->transferMetadata['chunkId'] . '_' . $this->transferMetadata['sessionId'] . '.sgChunk';
        }
        else
        {
            $fileName = $this->uploadDirectory . DIRECTORY_SEPARATOR .$this->transferMetadata['fileName'];
        }

        $destination = fopen($fileName, 'wb');

        if(!$destination)
        {
            fclose($inputStream);
            return false;
        }

        $writeSuccess = (stream_copy_to_stream($inputStream, $destination) !== false);

        fclose($inputStream);
        fclose($destination);

        return $writeSuccess;
    }

    private function writeFileFromChunks(int $retries)
    {
        $basePath = $this->uploadDirectory . DIRECTORY_SEPARATOR;
        if($this->renameAfterUpload === null)
        {
            $fileName = $this->transferMetadata['fileName'];

            if (!$this->overwriteExisting && file_exists($basePath . $fileName))
            {
                $sameFileNameCount = 0;
                $fileInfo          = pathinfo($basePath . $fileName);
                do
                {
                    $fileName = $fileInfo['filename'] . '_' . rand(1, 9999) . (++$sameFileNameCount) . '.' . $fileInfo['extension'];
                } while (file_exists($basePath . $fileName));
            }
        }
        else
        {
            $initialExtension = explode('.', $this->transferMetadata['fileName']);
            $initialExtension = $initialExtension ? '.'.end($initialExtension) : '';

            $fileName = $this->renameAfterUpload.$initialExtension;
        }

        $destinationStream = fopen($basePath .$fileName, 'wb');

        $totalChunks = $this->transferMetadata['totalChunks'];

        if(!$destinationStream)
        {
            if(--$retries > 0)
            {
                return $this->writeFileFromChunks($retries);
            }

            return false;
        }
        else
        {
            $retries = $this->finalFileMergingRetries;
        }

        for($dataChunkId = 0; $dataChunkId < $totalChunks; ++$dataChunkId)
        {
            $temporaryFile =  $basePath.$dataChunkId . '_' . $this->transferMetadata['sessionId'] . '.sgChunk';
            if(!file_exists($temporaryFile))
            {
                if(--$retries > 0)
                {
                    --$dataChunkId;
                    continue;
                }
                else
                {
                    fclose($destinationStream);
                    return false;
                }
            }

            $temporaryStream = fopen($temporaryFile, 'rb');

            if(!$temporaryStream)
            {
                if(--$retries > 0)
                {
                    fclose($temporaryStream);
                    --$dataChunkId;
                    continue;
                }
                else
                {
                    fclose($temporaryStream);
                    fclose($destinationStream);
                    return false;
                }
            }

            if(!stream_copy_to_stream($temporaryStream, $destinationStream))
            {
                if(--$retries > 0)
                {
                    fclose($temporaryStream);
                    --$dataChunkId;
                    continue;
                }
                else
                {
                    fclose($temporaryStream);
                    fclose($destinationStream);
                    return false;
                }
            }

            fclose($temporaryStream);
            unlink($temporaryFile);
        }
        fclose($destinationStream);
        return true;
    }

    private function extractTransferMetadata()
    {
        if(!isset(
            $_SERVER['HTTP_X_CONTENT_NAME'],
            $_SERVER['HTTP_X_CHUNK_ID'],
            $_SERVER['HTTP_X_CONTENT_LENGTH'],
            $_SERVER['HTTP_X_SESSION_NAME'],
            $_SERVER['HTTP_X_CHUNK_COUNT'],
            $_SERVER['CONTENT_LENGTH'],
            $_SERVER['HTTP_X_CHUNK_CHECKSUM']
        ))
        {
            return false;
        }

        $this->transferMetadata = [
            'fileName' => $_SERVER['HTTP_X_CONTENT_NAME'],
            'chunkId' => $_SERVER['HTTP_X_CHUNK_ID'],
            'chunkSize' => $_SERVER['CONTENT_LENGTH'],
            'fileSize' => $_SERVER['HTTP_X_CONTENT_LENGTH'],
            'totalChunks' => $_SERVER['HTTP_X_CHUNK_COUNT'],
            'sessionName' => $_SERVER['HTTP_X_SESSION_NAME'],
            'fileChecksum' => $_SERVER['HTTP_X_SESSION_NAME'],
            'integrityCheck' => ($_SERVER['HTTP_X_CHUNK_CHECKSUM'] == $this->calculateChecksum($_SERVER['HTTP_X_CHUNK_ID'].$_SERVER['HTTP_X_CONTENT_NAME'].$_SERVER['HTTP_X_CONTENT_LENGTH'])),
            'sessionId' => $this->calculateChecksum($_SERVER['HTTP_X_CONTENT_LENGTH'] . $_SERVER['HTTP_X_CONTENT_NAME'])
        ];

        return true;
    }

    private function sendTransferSuccess()
    {
        echo json_encode([
            'success' => true,
            'fileProgress' => 0,
            'chunkId' => $this->transferMetadata['chunkId'],
            'sessionData' => $this->fileStorage
        ]);
        exit;
    }

    private function sendTransferError(string $errorMsg = '')
    {
        echo json_encode([
            'success' => false,
            'msg' => $errorMsg,
            'chunkId' => $this->transferMetadata['chunkId'],
            'sessionData' => $this->fileStorage
        ]);
        exit;
    }

    private function calculateChecksum($string)
    {
        return sprintf('%u', crc32($string));
    }
}