<?php
namespace App\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

trait HasFileUpload
{
    public function updateFile(UploadedFile $file, $attribute, $storagePath)
    {
        tap($this->{$attribute}, function ($previous) use ($file, $storagePath, $attribute) {
            $this->forceFill([
                $attribute => $file->storePublicly(
                    $storagePath, ['disk' => $this->disk()]
                ),
            ])->save();

            if ($previous) {
                Storage::disk($this->disk())->delete($previous);
            }
        });
    }
    /**
     * Store the uploaded file on a filesystem disk.
     *
     * @param  UploadedFile|string  $file
     * @param  string  $storagePath
     * @param string|null $previous
     * @return string|false
     */
    public function uploadFile($file, $storagePath, $previous = null)
    {
        if(($file instanceof UploadedFile)) {
            $file = $file->storePublicly($storagePath, ['disk' => $this->disk()]);
        } else {
            $file = Storage::disk($this->disk())->put($storagePath, $file, 'public');
            if($file) {
                $file = $storagePath;
            }
        }
        
        if ($previous) {
            Storage::disk($this->disk())->delete($previous);
        }

        return $file;
    }

    public function deleteFile($attribute)
    {
        if(Storage::disk($this->disk())->exists($this->{$attribute}))
            Storage::disk($this->disk())->delete($this->{$attribute});

        return null;
    }

    /**
     * Create a new file with the given file name and path.
     *
     * @param  string  $fileName
     * @param  string  $filePath
     * @return string|false
     */
    public function createFile($fileName, $fileContents = '')
    {
        $file = Storage::disk($this->disk())->put($fileName, $fileContents, 'public');
        if($file)
            return $fileName;
        else
          return $file;
    }

    public function openFile1($fileName, $mode)
    {
        $filesystem = Storage::disk($this->disk());
        if ($filesystem->exists($fileName)) {
            // Open the file stream
            $stream = $filesystem->readStream($fileName);
    
            if ($stream !== false) {
                // Open the file resource
                $fileResource = fopen(stream_get_meta_data($stream)['uri'], $mode);
    
                // Check if opening the file resource was successful
                if ($fileResource !== false) {
                    return $fileResource;
                } else {
                    // Handle the case where opening the file resource failed
                    return false;
                }
            }
        }

        return false;
    }
    public function openFile($fileName, $mode)
    {
        $filesystem = Storage::disk($this->disk());

        // Open the file stream
        $stream = $filesystem->readStream($fileName);

        if ($stream !== false) {
            // Open the file resource
            $fileResource = fopen(stream_get_meta_data($stream)['uri'], $mode);

            // Check if opening the file resource was successful
            if ($fileResource !== false) {
                return $fileResource;
            } else {
                // Handle the case where opening the file resource failed
                return false;
            }
        }

        return false;
    }



    public function getFileUrl($attribute)
    {
        return $this->getFullUrl($this->{$attribute});
    }

    // public function append($path, $file) {
    //     // Download the existing file from S3
    //     $existingContent = Storage::disk($this->disk())->get($path);

    //     // Create a temporary file locally
    //     $tempLocalFilePath = tempnam(sys_get_temp_dir(), 'temp_csv');
    //     file_put_contents($tempLocalFilePath, $existingContent);

    //     // Open the local file in append mode
    //     $stream = fopen($tempLocalFilePath, 'a');

    //     // Append the new content to the local file
    //     stream_copy_to_stream($file, $stream);

    //     // Close the local stream
    //     fclose($stream);

    //     // Upload the updated file back to S3
    //     Storage::disk($this->disk())->put($path, file_get_contents($tempLocalFilePath), 'public');

    //     // Remove the temporary local file
    //     unlink($tempLocalFilePath);

    //     // Set visibility and return the path
    //     Storage::disk($this->disk())->setVisibility($path, 'public');
    //     return $path;
    // }
    public function appendToTemp($pathInS3)
    {
     
            // Download the existing file from S3
            $existingContent = Storage::disk($this->disk())->get($pathInS3);
        
            // Create a temporary local file
            $tempLocalFilePath = tempnam(sys_get_temp_dir(), 'temp_csv');
            file_put_contents($tempLocalFilePath, $existingContent);

            // Open the local file in append mode
            $stream = fopen($tempLocalFilePath, 'a');
          
            return  ['stream' => $stream, 'tempLocalFilePath' => $tempLocalFilePath ];

        return $stream;
    }

    public function putData($tempLocalFilePath, $pathInS3){

        Storage::disk($this->disk())->put($pathInS3, file_get_contents($tempLocalFilePath), 'public');

        // Remove the temporary local file
        unlink($tempLocalFilePath);

        // Set visibility and return the S3 path
        Storage::disk($this->disk())->setVisibility($pathInS3, 'public');
      
    }
    public function appendToS3($csvData, $pathInS3)
    {
        // Download the existing file from S3
        $existingContent = Storage::disk($this->disk())->get($pathInS3);
    
        // Create a temporary local file
        $tempLocalFilePath = tempnam(sys_get_temp_dir(), 'temp_csv');
        file_put_contents($tempLocalFilePath, $existingContent);

        // Open the local file in append mode
        $stream = fopen($tempLocalFilePath, 'a');

        // Append the new CSV data to the local file
        fputcsv($stream, $csvData);

        // Close the local stream
        fclose($stream);

        // Upload the updated file back to S3
        Storage::disk($this->disk())->put($pathInS3, file_get_contents($tempLocalFilePath), 'public');

        // Remove the temporary local file
        unlink($tempLocalFilePath);

        // Set visibility and return the S3 path
        Storage::disk($this->disk())->setVisibility($pathInS3, 'public');
        return $pathInS3;
    }

    public function getFullUrl($path) {
        return Storage::disk($this->disk())->url($path);
    }

    protected function disk()
    {
        return config('filesystems.default');
    }
}