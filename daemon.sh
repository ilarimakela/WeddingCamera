#!/bin/bash 

function printMeta {
   clear

   figlet -f smslant -w 200 Wedding Camera

   UNLOADED=$(mysql weddingCamera -e 'select count(*) from pictures where downloaded = 0\G' | grep 'count' | awk '{ print $2 }')
   PRINTING_QUEUE=$(lpq | grep -v printing | grep -v Rank | grep -v 'no entries' | grep -v 'is ready' | wc -l | awk '{ print $1 }')
   
   echo ''
   if [ $UNLOADED -gt 0 ]
   then
      echo "$UNLOADED new pictures in camera"
   else
      echo ''
   fi

   if [ $PRINTING_QUEUE -gt 0 ]
   then
      echo "Printing queue $PRINTING_QUEUE"
   else
      echo ''
   fi

   echo ''
}

while true; do
   
   printMeta
   php weddingCamera.php 

   sleep 10;
done
