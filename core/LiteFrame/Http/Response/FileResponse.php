<?php

namespace LiteFrame\Http\Response;

use Exception;
use LiteFrame\Http\Response;

class FileResponse extends Response
{
    protected $path;
    protected $fileRange;
    protected $filesize;

    public function __construct($path)
    {
        if (!is_readable($path)) {
            throw new Exception("File $path not found or inaccessible");
        }

        $this->setContent($path);
    }

    public function setContent($path)
    {
        $this->path = $path;
    }

    public function guessContentType()
    {
        $ext = pathinfo($this->path, PATHINFO_EXTENSION);
        $known_mime_types = array(
            "htm" => "text/html",
            "exe" => "application/octet-stream",
            "zip" => "application/zip",
            "doc" => "application/msword",
            "jpg" => "image/jpg",
            "php" => "text/plain",
            "xls" => "application/vnd.ms-excel",
            "ppt" => "application/vnd.ms-powerpoint",
            "gif" => "image/gif",
            "pdf" => "application/pdf",
            "txt" => "text/plain",
            "html" => "text/html",
            "png" => "image/png",
            "jpeg" => "image/jpg"
        );
        return isset($known_mime_types[$ext]) ?
                $known_mime_types[$ext] :
                "application/force-download";
    }

    public function download($name = null)
    {
        if (empty($name)) {
            $name = basename($this->path);
        }
        $this->header("Content-Description: File Transfer");
        $this->header("Content-Disposition: attachment; filename='$name'");
        $this->header("Content-Transfer-Encoding: binary");
        $this->header('Accept-Ranges: bytes');
    }

    public function getContent()
    {
        if (!$this->content) {
            $this->content = file_get_contents($this->path);
        }
        return $this->content;
    }

    private function updateHeaders()
    {
        $mimeType = $this->guessContentType();
        $this->header('Content-Type', $mimeType);

        $size = $this->fileSize();
        if (isset($_SERVER['HTTP_RANGE'])) {
            list($range_start, $range_end) = $this->getRequestedFileRange();
            $r_length = $this->getRequestedFileLength();
            $this->header("HTTP/1.1 206 Partial Content");
            $this->header("Content-Length: $r_length");
            $this->header("Content-Range: bytes $range_start-$range_end/$size");
        } else {
            $this->header("Content-Length: " . $size);
        }
    }

    private function getRequestedFileRange()
    {
        if (empty($this->fileRange) && isset($_SERVER['HTTP_RANGE'])) {
            $size = $this->fileSize();
        
            list($a, $http_range_value) = explode("=", $_SERVER['HTTP_RANGE'], 2);
            list($range) = explode(",", $http_range_value, 2);
            list($range_start_str, $range_end_str) = explode("-", $range);
            $range_start = intval($range_start_str);
            if (!$range_end_str) {
                $range_end = $size - 1;
            } else {
                $range_end = intval($range_end_str);
            }
            $this->fileRange = [$range_start, $range_end];
        }
        return $this->fileRange;
    }
    
    private function getRequestedFileLength()
    {
        list($range_start, $range_end) = $this->getRequestedFileRange();
        return $range_end - $range_start + 1;
    }

    private function fileSize()
    {
        if (!$this->filesize) {
            return filesize($this->path);
        }
        return $this->filesize;
    }

    /**
     * Outputs the content of this response object.
     * All headers will be sent in the order they were added.
     *
     * @return type
     */
    public function output()
    {
        $this->updateHeaders();

        @ob_end_clean();
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
        }

        //Set headers
        header($this->getStatusText());
        foreach ($this->headers as $header) {
            header($header);
        }

        set_time_limit(0);
        return $this->streamfile();
    }

    public function streamfile()
    {
        $chunksize = 1 * (1024 * 1024);
        $bytes_send = 0;
        $file = fopen($this->path, 'r');
        list($range_start, $range_end) = $this->getRequestedFileRange();
        if ($file) {
            if (isset($_SERVER['HTTP_RANGE'])) {
                fseek($file, $range_start);
                $length = $this->getRequestedFileLength();
            } else {
                $length = $this->fileSize();
            }

            while (!feof($file) &&
            (!connection_aborted()) &&
            ($bytes_send < $length)
            ) {
                $buffer = fread($file, $chunksize);
                echo($buffer);
                flush();
                $bytes_send += strlen($buffer);
            }
            fclose($file);
        } else {
            throw new Exception("Can not open file $this->path");
        }
    }
}
