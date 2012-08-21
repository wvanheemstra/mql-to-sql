<?php

//The yui_url variable is used only by the query editor.
//note: by default, the hosted yui resources are used.
//Set up a local instance of YUI 2 in case you want rely
//on having access to the internett
$yui_url = 'http://yui.yahooapis.com/2.8.1';
//$yui_url = 'http://localhost/yui_2.8.0r4';

//note: the file with the connection data contains an absolute path
//to the sqlite database file. You have to modify that to match the
//location on your system or else it won't work!

//$connection_file_name = '../schema/connection-sqlite.json';
//$connection_file_name = '../schema/connection-postgresql.json';
//$connection_file_name = '../schema/connection-oracle.json';
$connection_file_name = '../schema/connection-mysql.json';

//$metadata_file_name = '../schema/friendly-schema-sqlite.json';
$metadata_file_name = '../schema/schema.json';
//$metadata_file_name = '../schema/schema-postgresql.json';

