# Plugin overview
A simple file uploader

* Does not require any libraries
* Suports uploading of large files via multiple smaller-sized data chunks (this also allows bypassing the php upload limit)
* Includes server-side handling script (PHP)

# Usage
<h4>JS Plugin</h4>
```javascript
// 1. Include script file <script src="singularityUploader.min.js"></script>
// 2 . Create an instance 
  singularityUploader.create('targetId', {
    transferDestination: 'uploadFiles.php' // NOTE: This will handle our uploads
});
```

Available options:
```
{
  transferDestination:'', // NOTE: Script that will handle the uploads
  allowedFileExtensions:[], // NOTE: Accepted file extensions
  maxFileSize:null, // NOTE: Max file size in bytes
  maxConcurentTransfers: 5, // NOTE: Max concurent chunk transfers per file
  maxFileChunkSize: 2097152, // NOTE: bytes per data chunk
  multipleFiles:false, // NOTE: Single/multiple file upload
  sessionName: 'singularityUpload', //NOTE: The session name that the php script will use to store uploaded files
  maxRetries: 3, // NOTE: Max number of chunk retries
  success:null, // Succes callback - called after the last file has been uploaded and success confirmation from handling script is received
  error:null, // NOTE: Not yet implemented
  uploadButtonText: 'Upload' // NOTE: Upload button displayed text
}
```

<h4>Server-side</h4>

```
$uploadSession = new UploadManager();

// NOTE: Configure settings per upload session
$uploadSession->getUploadSessionConfig('singularityUpload') // NOTE: The session name should reflect the 'sessionName' js option or files will not be stored correctly
                ->setUploadPath(__DIR__.DIRECTORY_SEPARATOR.'uploads')
                ->setAlowMultipleFiles(true) // NOTE: This should reflect what was set in the js options so that files are not lost
                ->setAllowedFileExtensions(['doc', 'docx']) // NOTE: This should reflect what was set in the js options so that files are not lost
;

$uploadSession->handleUploads(); // NOTE: Start listening for incoming files
```
