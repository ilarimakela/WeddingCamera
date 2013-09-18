<?php

require __DIR__ . '/trunk/Base/src/ezc_bootstrap.php';

class weddingCamera
{
   const CAMERAIP = "192.168.11.254";
   const TIMEOUT = 180;

   private $listUrl;
   private $downloadUrl;
   private $fs = array();
   private $db;

   private $folders;

   function __construct()
   {
      $this->listUrl = 
         "http://" . self::CAMERAIP . "/cgi-bin/tslist?keeprefresh="
         . microtime(true) . '?';

      $this->downloadUrl = 
         "http://" . self::CAMERAIP . "/cgi-bin/wifi_download?";

      $this->folders = array(
         "temp" => __DIR__ . '/folders/tmp/',
         "print" => __DIR__ . '/folders/print/',
         "done" => __DIR__ . '/folders/done/'
      );

      foreach($this->folders as $folder)
         is_dir($folder) || mkdir($folder, 0777, true);

      $this->updateDirs();
      $this->syncToMariaDB();
      $this->downloadNewPictures();
      $this->printPictures();
   }

   function updateDirs()
   {
      $subFolders = $this->readFolder("/www/sd/DCIM");

      if( ! is_null($subFolders))
      {
         foreach($subFolders as $folder)
            $this->readFolder($folder);
      }
   }

   function syncToMariaDB()
   {
      if(empty($this->fs))
         return;

      $db = $this->getConnection();

      foreach($this->fs as $picture)
      {
         $this->savePictureMetaToDB($db, $picture);  
      }

      $this->cleanDB();
   }

   function downloadNewPictures()
   {
      $db = $this->getConnection();

      $pictures = $this->getUndownloadedPictures($db);

      if(is_null($pictures))
         return;

      # $this->cleanTempFolder();

      for($i=0, $count = count($pictures); $i < $count; $i++)
         if($this->downloadPicture($pictures[$i]) === false)
            return;
   }

   function printPictures()
   {
      $pictures = $this->selectPicturesForPrinter();

      if(empty($pictures))
         return;

      foreach($pictures as $picture)
      {
         $this->printPicture($picture);
         $this->moveToDoneFolder($picture);
      }
   }

   private function readFolder($path)
   {
      $result = $this->curl(
         $this->listUrl . http_build_query(array('PATH' => $path))
      );
      
      if(is_null($result))
         return null;

      return $this->parseFolderResult($path, $result);
   }

   private function curl($url)
   {
      $ch = curl_init();
      curl_setopt_array($ch, array(
         CURLOPT_URL => $url,
         CURLOPT_HEADER => 0,
         CURLOPT_RETURNTRANSFER => TRUE,
         CURLOPT_TIMEOUT => 4
      ));
      if( ! $result = curl_exec($ch))
      {
         return null;
      }
      curl_close($ch);

      return $result;
   }

   private function parseFolderResult($path, $result)
   {
      $explode = explode("\n", $result);

      if(empty($explode[2]))
         return null;

      $filenames = array();
      if( ! preg_match_all("/FileName[0-9]+=([0-9A-Za-z\_]+)/", 
         $explode[2], $filenames))
         return null;

      $filetypes = array();
      if( ! preg_match_all("/FileType[0-9]+=(File|Directory)/", 
         $explode[2], $filetypes))
         return null;

      if(empty($filetypes))
         return null;

      $subFolders = array();
      for($i = 0, $count = count($filetypes[1]); $i < $count; $i++)
      {
         $filename = null;
         if($filetypes[1][$i] === 'Directory')
         {
            $folder = $path . '/' . $filenames[1][$i] . '/';
            $subFolders[] = $folder;
         }
         else if (
            $filetypes[1][$i] === 'File' 
            && preg_match_all(
               '/^(IMG_[0-9]{4,6})$/', 
               $filenames[1][$i], 
               $filename
            )
         )
         {
            $this->fs[] = 
               $folder = $path . $filename[0][0] . '.JPG';
         }
      }

      return $subFolders;
   }

   private function getConnection()
   {
      if( ! is_null($this->db))
         return $this->db;

      $this->db = new mysqli("localhost", "root", "", "weddingCamera");

      if ($this->db->connect_errno)
          throw new Exception('Now connection to DB: ' . $mysqli->connect_error);
      
      return $this->db; 
   }

   private function savePictureMetaToDB($db, $picture)
   {
      if( ! $this->pictureIsInDB($db, $picture))
      {
         $response = $db->query("
            INSERT INTO 
               pictures(path)
            VALUES(
               '$picture'
            )
         ");

         if( ! $response)
            throw new Exception(
               "Inserting picture '$picture' failed: " . $db->error
            );
      }
   }

   private function pictureIsInDB($db, $picture)
   {
      $response = $db->query("
         SELECT
            id
         FROM
            pictures
         WHERE
            path = '$picture'
      ");

      if( ! $response)
         throw new Exception(
            "Selecting picture '$picture' failed: " . $db->error
         );

      $row = $response->fetch_assoc();

      return ! is_null($row);
   }

   private function getUndownloadedPictures($db)
   {
      $response = $db->query("
         SELECT
            *
         FROM
            pictures
         WHERE
            downloaded = 0
      ");

      if( ! $response)
         throw new Exception(
            "Selecting undownloaded pictures failed: " . $db->error
         );

      $pictures = array();

      while ($row = $response->fetch_assoc())
      {
          $pictures[] = $row;
      }

      return empty($pictures) ? null : $pictures;
   }

   private function cleanTempFolder()
   {
      $tempFiles = glob($this->folders['temp'] . '*');

      if(empty($tempFiles))
         return;

      foreach($tempFiles as $file)
         unlink($file);
   }

   private function downloadPicture($picture)
   {
      $filename = pathinfo($picture['path'], PATHINFO_BASENAME);
      $url = $this->createDownloadUrl($picture['path']);
      if($this->downloadPictureFromCamera($url, $filename) === false)
         return;

      $this->copyDownloadedPicture($filename);
      $this->markAsDownloaded($picture['id']);
   }

   private function createDownloadUrl($picture)
   {
      $filename = pathinfo($picture, PATHINFO_BASENAME);
      $dir = pathinfo($picture, PATHINFO_DIRNAME);

      return $this->downloadUrl .
         http_build_query(
            array(
               'fn' => $filename,
               'fd' => $dir
            )
         );
   }

   private function downloadPictureFromCamera($url, $filename)
   {
      $tempFile = $this->folders['temp'] . $filename;
      $fp = fopen($tempFile, 'wb');

      $filesize = $this->getFileSize($url);

      if(is_null($filesize))
         return false;

      $GLOBALS['current'] = new progressBar;
      $GLOBALS['current']->setSize($filesize);
      $curlErrorLog = fopen('/dev/null', 'wb');
      $ch = curl_init();
      curl_setopt_array($ch, array(
         CURLOPT_URL => $url,
         CURLOPT_FILE => $fp,
         CURLOPT_HEADER => 0,
         CURLOPT_CONNECTTIMEOUT => 2,
         CURLOPT_TIMEOUT => self::TIMEOUT,
         CURLOPT_STDERR => $curlErrorLog,
         CURLOPT_PROGRESSFUNCTION => 'progressBar',
         CURLOPT_NOPROGRESS => false
      ));

      if(curl_exec($ch) === false)
      {
         if(curl_errno($ch) === 7)
         {
            curl_close($ch);
            fclose($fp);
            $GLOBALS['current']->finnish();
            unset($GLOBALS['current']);

            return false;
         }
         else
         {
            curl_close($ch);
            fclose($fp);
            $GLOBALS['current']->finnish();
            unset($GLOBALS['current']);

            return false;
         }
      }
      curl_close($ch);
      fclose($fp);
      $GLOBALS['current']->finnish();
      unset($GLOBALS['current']);
   }

   private function getFileSize($url)
   { 
      $ch = curl_init($url); 

      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE); 
      curl_setopt($ch, CURLOPT_HEADER, TRUE); 
      curl_setopt($ch, CURLOPT_NOBODY, TRUE); 

      $data = curl_exec($ch); 
      $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD); 

      curl_close($ch); 

      return $size == -1 ? null : $size;
   }

   private function copyDownloadedPicture($filename)
   {
      rename(
         $this->folders['temp'] . $filename,
         $this->folders['print'] . $filename
      );
   }

   private function markAsDownloaded($id)
   {
      $db = $this->getConnection();

      $response = $db->query("
         UPDATE
            pictures
         SET
            downloaded = true
         WHERE
            id = $id
      ");

      if( ! $response)
         throw new Exception(
            "Setting picture '$id' as downloaded failed: " . $db->error
         );
   }

   private function selectPicturesForPrinter()
   {
      return glob($this->folders['print'] . '*');
   }

   private function printPicture($picture)
   {
      exec('lp -o fitplot -o media=4x6 ' . $picture);
   }

   private function moveToDoneFolder($picture)
   {
      rename(
         $picture,
         $this->folders['done'] . pathinfo($picture, PATHINFO_BASENAME)
      );
   }

   private function cleanDB()
   {
      $cameraPaths = "'" . implode("', '", $this->fs) . "'";

      $db = $this->getConnection();
      
      $response = $db->query("
         DELETE FROM
            pictures
         WHERE
            downloaded = 0
            AND path not in ($cameraPaths)               
      ");

      if( ! $response)
         throw new Exception(
            $db->error
         );
   }

}

function progressBar($fileSize, $downloaded)
{
   $GLOBALS['current']->advance($downloaded);
}

class progressBar
{
   private $output;
   private $bar;

   private $size;
   private $currentPosition;

   function __construct()
   {
      $this->output = new ezcConsoleOutput();
   }

   function setSize($size)
   {
      $this->size = $size;
      $this->bar = new ezcConsoleProgressbar(
         $this->output, 
         $size,
         array(
            'barChar' => "=",
            'emptyChar' => " ",
            'formatString' => "%act% / %max% [%bar%] %fraction%%",
            'fractionFormat' => "%01.2f",
            'progressChar' => ">",
            'redrawFrequency' => 1,
            'step' => 1,
            'width' => 65,
            'actFormat' => '%.0f',
            'maxFormat' => '%.0f',
            'minVerbosity' => 1,
            'maxVerbosity' => false,
         )
      );
   }

   function advance($downloaded)
   {
      $step = $downloaded - $this->currentPosition;
      $this->bar->advance(true, $step);

      $this->currentPosition = $downloaded;
   }

   function finnish()
   {
      $this->bar->finish();
      $this->output->outputLine();
   }

}

new weddingCamera;
