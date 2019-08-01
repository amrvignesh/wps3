<?php
/*
Plugin Name: S3 Uploads Offloader
Description: Offload WordPress uploads directory to S3 compatible storage
Version: 0.1
Author: Vignesh AMR
*/

// Define AWS SDK dependency
require_once(ABSPATH . 'wp-content/plugins/s3-uploads/vendor/autoload.php');

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

// Initialize the S3 client
$s3_client = new S3Client([
    'version' => 'latest',
    'region' => 'your_s3_bucket_region',
    'credentials' => [
        'key' => 'your_s3_access_key',
        'secret' => 'your_s3_secret_key',
    ],
]);
