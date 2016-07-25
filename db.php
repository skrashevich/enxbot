<?php

//
// config.php
//
//$mysql_server = '';
//$mysql_user = '';
//$mysql_pass = '';
//$mysql_db = '';

mysql_connect($mysql_server, $mysql_user, $mysql_pass);
mysql_select_db($mysql_db);
mysql_query('set names utf8');

unset($mysql_pass);