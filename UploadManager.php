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
    private $currentTransferSession;
    private $finalFileMergingRetries; // NOTE: Number of retries for writing the final file from chunks before aborting
    private $uploadSessionsConfig;

    public function __construct()
    {
        if(session_status() == PHP_SESSION_NONE)
        {
            session_start();
        }
        $this->fileStorage = [];
        $this->finalFileMergingRetries = 3;
    }

    /**
     * @param string $uploadSession
     * @return array|string An array if multiple files were allowed or a string if single (will be an empty string if no files were stored in session for both cases)
     */
    public function getUploadedFiles(string $uploadSession)
    {
        return isset($_SESSION[$uploadSession]) ? $_SESSION[$uploadSession] : '';
    }

    /**
     * @param string $sessionName
     * @return UploadManagerConfig
     */
    public function getUploadSessionConfig(string $sessionName)
    {
        if(!isset($this->uploadSessionsConfig[$sessionName]))
        {
            $this->uploadSessionsConfig[$sessionName] = new UploadManagerConfig();
        }

        return $this->uploadSessionsConfig[$sessionName];
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

    public function handleUploads()
    {
        if(!$this->extractTransferMetadata())
        {
            $this->sendTransferError();
        }

        if(!$this->transferMetadata['integrityCheck'])
        {
            $this->sendTransferError('File integrity check fail!');// NOTE: Data may have been tampered with
        }

        $validUpload = $this->isValidUpload();
        if($validUpload !== true)
        {
            $this->sendTransferError($validUpload, false);
        }

        $this->currentTransferSession = 'UploadManagerStorage_'.$this->transferMetadata['sessionName'].$this->transferMetadata['sessionId'];

        if(isset($_SESSION[$this->currentTransferSession]))
        {
            $this->fileStorage = $_SESSION[$this->currentTransferSession];
        }

        $this->uploadDirectory = $this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getUploadPath();

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
            $fileName = $this->uploadDirectory . DIRECTORY_SEPARATOR . $this->getFinalFilename();
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

        if(($this->transferMetadata['totalChunks'] == 1) && $writeSuccess)
        {
            $this->storeUploadedFile($fileName);
        }

        return $writeSuccess;
    }

    private function writeFileFromChunks(int $retries)
    {
        $basePath = $this->uploadDirectory . DIRECTORY_SEPARATOR;
        $fileName = $this->getFinalFilename();

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

        if(!$this->isValidFileSize(filesize($basePath .$fileName)))
        {
            unlink($basePath .$fileName);
            return false;
        }

        $this->storeUploadedFile($basePath .$fileName);

        return true;
    }

    private function storeUploadedFile(string $filePath)
    {
        if($this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getAllowMultipleFiles())
        {
            if(!isset($_SESSION[$this->transferMetadata['sessionName']]))
            {
                $_SESSION[$this->transferMetadata['sessionName']] = [];
            }
            $_SESSION[$this->transferMetadata['sessionName']][$filePath] = $filePath;
        }
        else
        {
            $_SESSION[$this->transferMetadata['sessionName']] = $filePath;
        }
    }

    /**
     * Gets the filename resulted after checking the configuration flags (overwrite existing files, rename after upload, etc.)
     * @return string
     */
    private function getFinalFilename()
    {
        $uploadPath = $this->uploadDirectory . DIRECTORY_SEPARATOR;
        $fileName = $this->transferMetadata['fileName'];

        if($this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getRenameFileAfterUpload() === null)
        {
            if (!$this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getOverwriteExistingFiles() && file_exists($uploadPath . $fileName))
            {
                $sameFileNameCount = 0;
                $fileInfo          = pathinfo($uploadPath . $fileName);
                $fileInfo['extension'] = strtolower($fileInfo['extension']);
                do
                {
                    $fileName = $fileInfo['filename'] . '_' . rand(1, 9999) . (++$sameFileNameCount) . '.' . $fileInfo['extension'];
                } while (file_exists($uploadPath . $fileName));
            }
        }
        else
        {
            $initialExtension = explode('.', $this->transferMetadata['fileName']);
            $initialExtension = $initialExtension ? '.'.strtolower(end($initialExtension)) : '';

            $fileName = $this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getRenameFileAfterUpload().$initialExtension;
        }

        return $fileName;
    }

    /**
     * @return bool|string Boolean true if the upload session is valid, en error message otherwhise
     */
    private function isValidUpload()
    {
        if(!$this->isValidFileName($this->transferMetadata['fileName']))
        {
            return 'File name contains invalid characters!';
        }
        else if(!$this->isValidFileExtension($this->transferMetadata['fileName']))
        {
            return 'File has an invalid extension!';
        }
        else if(!$this->isValidFileSize(max($this->transferMetadata['fileSize'], ($this->transferMetadata['totalChunks'] * $this->transferMetadata['chunkSize']))))
        {
            return 'File exceeds allowed max size of '.$this->bytesToHumanReadable($this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getMaxFileSize()).' !';
        }

        return true;
    }

    /**
     * @param string $fileName
     * @return bool
     */
    private function isValidFileExtension(string $fileName)
    {
        if(!$this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getAllowedFileExtensions())
        {
            return true;
        }
        $extension = explode('.', str_replace(' ', '', $fileName));
        return in_array(strtolower(end($extension)), $this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getAllowedFileExtensions());
    }

    private function isValidFileName(string $fileName)
    {
        return (strlen($fileName) > 0 && !preg_match('/\0|\/|\\\\|:|\*|<|>|\?/', $fileName));
    }

    private function isValidFileSize($size)
    {
        return $size <= $this->getUploadSessionConfig($this->transferMetadata['sessionName'])->getMaxFileSize();
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
            'success' => true
        ]);
        exit;
    }

    private function sendTransferError(string $errorMsg = '', $recoverableError = true)
    {
        echo json_encode([
            'success' => false,
            'msg' => $errorMsg,
            'recoverable' => $recoverableError
        ]);
        exit;
    }

    private function calculateChecksum($string)
    {
        return sprintf('%u', crc32($string));
    }

    private function bytesToHumanReadable($bytes)
    {
        $unit = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        return @round($bytes / pow(1024, ($i = floor(log($bytes, 1024)))), 2) . ' ' . $unit[$i];
    }
}

class UploadManagerConfig
{
    private $sessionConfig;

    public function __construct()
    {
        $this->sessionConfig = [
            'allowMultipleFiles' => false,
            'uploadPath' => '',
            'allowedExtensions' => [], // NOTE: Warning if empty all extensions are allowed
            'renameAfterUpload' => null,
            'overwriteExistingFiles' => true,
            'maxFileSize' => 10485760 // NOTE: ~10MB
        ];
    }

    /**
     * @param string $size A string that represents a file size. E.g. 10Mb, 1Gb, etc. If the size unit(b, kb, etc.) is not found then the string will be considered as bytes
     * @return $this
     */
    public function setMaxFileSize(string $size)
    {
        $value = trim($size);

        if($value == '')
        {
            return $this;
        }

        $unit = strtolower(substr($value,  -2));
        $unit = trim(preg_replace('/[^A-Za-z]+/', '', $unit));

        if($unit == '')
        {
            $unit = 'b';
        }

        $value = (int)preg_replace('/[^0-9.]+/', '', $value);

        if($unit == 'b')
        {
            if(!empty($value))
            {
                $this->sessionConfig['maxFileSize'] = $value;
            }

            return $this;
        }

        switch($unit)
        {
            case 'p':
            case 'pb':
                $value *= 1024;
            case 't':
            case 'tb':
                $value *= 1024;
            case 'g':
            case 'gb':
                $value *= 1024;
            case 'm':
            case 'mb':
                $value *= 1024;
            case 'k':
            case 'kb':
                $value *= 1024;
        }

        $this->sessionConfig['maxFileSize'] = $value;

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxFileSize()
    {
        return $this->sessionConfig['maxFileSize'];
    }

    /**
     * Weather multiple file uploads support will be enabled
     * @param bool $state
     * @return $this
     */
    public function setAlowMultipleFiles(bool $state = false)
    {
        $this->sessionConfig['allowMultipleFiles'] = $state;
        return $this;
    }

    /**
     * @return bool
     */
    public function getAllowMultipleFiles()
    {
        return $this->sessionConfig['allowMultipleFiles'];
    }

    /**
     * @param string $path
     * @return $this
     */
    public function setUploadPath(string $path)
    {
        $this->sessionConfig['uploadPath'] = rtrim($path, DIRECTORY_SEPARATOR);
        return $this;
    }

    /**
     * @return string
     */
    public function getUploadPath()
    {
        return $this->sessionConfig['uploadPath'];
    }


    /**
     * @param array $extensions All extensions should be lower-case and not the include extension dot '.'
     * @return $this
     */
    public function setAllowedFileExtensions(array $extensions)
    {
        $this->sessionConfig['allowedExtensions'] = $extensions;
        return $this;
    }

    /**
     * @return array
     */
    public function getAllowedFileExtensions()
    {
        return $this->sessionConfig['allowedExtensions'];
    }

    /**
     * Renames a file after upload is complete
     * @param string $newName File name (without extension) to be applied
     * WARNING! This method should be used on single file uploads only!
     * Also this WILL overwrite existing files with the same name
     *
     * @return $this
     */
    public function renameFileAfterUpload(string $newName)
    {
        $this->sessionConfig['renameAfterUpload'] = $newName;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getRenameFileAfterUpload()
    {
        return $this->sessionConfig['renameAfterUpload'];
    }

    /**
     * @param bool $state Default: true
     * @return $this
     */
    public function overwriteExistingFiles(bool $state = true)
    {
        $this->sessionConfig['overwriteExistingFiles'] = $state;
        return $this;
    }

    /**
     * @return bool
     */
    public function getOverwriteExistingFiles()
    {
        return $this->sessionConfig['overwriteExistingFiles'];
    }
}