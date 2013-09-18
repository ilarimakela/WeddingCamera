WeddingCamera
=============

Wedding Camera is application, which I made for my friends wedding. Best men wanted to make a photo guest book and this application is to make that easier. It downloads all new photos from camera thru Wi-Fi so no cables are needed between computer and camera. There is [Transcend Wi-Fi SD Card](http://uk.transcend-info.com/products/Catlist.asp?modno=401&cat_no=186) in the camera to make that possible. Wedding Camera also prints all photos it gets from the camera.

Requirements
------------

You need MySQL or MariaDB to store information of which photos are already downloaded. Main functionalities are made with PHP. Application is made for Mac but might also work with Linux. Won’t work with Windows.

Installation
------------

First create new database “weddingCamera” and run schema file mariaDB.sql into it.

Usage
-----
Just run ./daemon.sh to run the application.
