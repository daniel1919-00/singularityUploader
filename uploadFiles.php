<?php
/**
 * Created by PhpStorm.
 * User: singularityDev
 * Date: 08-Dec-18
 * Time: 09:04
 */

require 'UploadManager.php';

$uploadSession = new UploadManager();

// NOTE: Configure settings per upload session
$uploadSession->getUploadSessionConfig('singularityUpload')
                ->setUploadPath(__DIR__.DIRECTORY_SEPARATOR.'uploads')
                ->setAlowMultipleFiles(true)
                ->setAllowedFileExtensions(['doc', 'docx', 'pdf'])
                ->setMaxFileSize('200MB')
;

$uploadSession->handleUploads();