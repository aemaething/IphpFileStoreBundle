<?php

namespace Iphp\FileStoreBundle\FileStorage;

use Iphp\FileStoreBundle\FileStorage\FileStorageInterface;
use Iphp\FileStoreBundle\Mapping\PropertyMapping;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\File;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

/**
 * FileSystemStorage.
 *
 * @author Vitiko <vitiko@mail.ru>
 */
class FileSystemStorage implements FileStorageInterface
{

    protected $webDir;

    protected $sameFileChecker;

    /**
     * Constructs a new instance of FileSystemStorage.
     *
     * @param
     */
    public function __construct($webDir = null)
    {
        $this->webDir = $webDir;


        // @codeCoverageIgnoreStart
        $this->sameFileChecker = function (File $file, $fullFileName)
        {
            return $file->getRealPath() == realpath($fullFileName);
        };
        // @codeCoverageIgnoreEnd
    }

    public function setWebDir($webDir )
    {
        $this->webDir = $webDir;
    }

    public function getWebDir()
    {
        return $this->webDir;
    }

    public function setSameFileChecker (\Closure $checker)
    {
        $this->sameFileChecker = $checker;
    }




    protected function getOriginalName(File $file)
    {
        return $file instanceof UploadedFile ?
            $file->getClientOriginalName() : $file->getFilename();
    }


    protected function getMimeType(File $file)
    {
        return $file instanceof UploadedFile ?
            $file->getClientMimeType() : $file->getMimeType();
    }


    public function   isSameFile (File $file,  $fullFileName)
    {
        return  call_user_func(
            $this->sameFileChecker,
            $file,
            $fullFileName);

    }


    protected function copyFile($source, $directory, $name)
    {
        $this->checkDirectory($directory);
        $target = $directory . DIRECTORY_SEPARATOR . basename($name);

        if (!@copy($source, $target)) {
            $error = error_get_last();
            throw new FileException(sprintf('Could not copy the file "%s" to "%s" (%s)', $source, $target, strip_tags($error['message'])));
        }

        @chmod($target, 0666 & ~umask());

        return new File($target);
    }







    protected function checkDirectory ($directory)
    {
        if (!is_dir($directory)) {
            if (false === @mkdir($directory, 0777, true)) {

                // @codeCoverageIgnoreStart
                throw new FileException(sprintf('Unable to create the "%s" directory', $directory));
                // @codeCoverageIgnoreEnd
            }
        } elseif (!is_writable($directory)) {
            // @codeCoverageIgnoreStart
            throw new FileException(sprintf('Unable to write in the "%s" directory', $directory));
            // @codeCoverageIgnoreEnd
        }

        return true;
    }


    /**
     * {@inheritDoc}
     * File may be \Symfony\Component\HttpFoundation\File\File or \Symfony\Component\HttpFoundation\File\UploadedFile
     */
    public function upload(PropertyMapping $mapping, File $file)
    {
        $originalName = $this->getOriginalName($file);
        $mimeType = $this->getMimeType($file);

        //transform filename and directory name if namer exists in mapping definition
        list ($fileName, $webPath) = $mapping->prepareFileName($originalName, $this);
        $fullFileName = $mapping->resolveFileName($fileName);

        //check if file already placed in needed position
        if (!$this->isSameFile($file, $fullFileName)) {
            $fileInfo = pathinfo($fullFileName);

            if ($file instanceof UploadedFile)
            {
                $this->checkDirectory($fileInfo['dirname']);
                $file->move($fileInfo['dirname'], $fileInfo['basename']);
            }
            else  $this->copyFile($file->getPathname(), $fileInfo['dirname'], $fileInfo['basename']);
        }


        $fileData = array(
            'fileName' => $fileName,
            'originalName' => $originalName,
            'mimeType' => $mimeType,
            'size' => filesize($fullFileName),
            'path' => $webPath
        );

        if (!$fileData['path'])
            $fileData['path'] = substr($fullFileName, strlen($this->webDir));


        $ext = substr($originalName,strrpos ($originalName,'.')+1);

         if (function_exists('getimagesize') && (
                    in_array($fileData['mimeType'], array('image/gif', 'image/png', 'image/jpeg', 'image/pjpeg'))
                 || in_array($ext, array('gif', 'jpeg', 'jpg', 'png'))
             )) {
 
             $imgDimensions = @getimagesize($fullFileName);
             if ($imgDimensions !== false) {
                 $fileData['width'] = $imgDimensions[0];
                 $fileData['height'] = $imgDimensions[1];
             }
 
         } elseif (function_exists('simplexml_load_file') && ($fileData['mimeType'] == 'image/svg+xml' || $ext == 'svg')) {
 
             $xml = @simplexml_load_file($fullFileName);
             if (isset($xml['width']) && isset($xml['height'])) {
                 $fileData['width'] = substr($xml['width'], 0, -2);
                 $fileData['height'] = substr($xml['height'], 0, -2);
             }
         }


        return $fileData;
    }


    /**
     *  {@inheritDoc}
     */
    public function removeFile($fullFileName)
    {

        if ($fullFileName && file_exists($fullFileName)) {
            @unlink($fullFileName);
            return !file_exists($fullFileName);
        }
        return null;
    }


    /**
     *  {@inheritDoc}
     */
    public function fileExists($fullFileName)
    {
        return file_exists($fullFileName);
    }






}
