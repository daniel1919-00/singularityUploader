<?php
/**
 * Created by PhpStorm.
 * User: singularityDev
 * Date: 08-Dec-18
 * Time: 09:04
 */

require 'UploadManager.php';

$uploadSession = new UploadManager(__DIR__.DIRECTORY_SEPARATOR.'uploads');
$uploadSession->overwriteExistingFiles(false)
              ->handleUploads();