<?php
return array(
	'counter' => array( 'name' => 'demo/counter', 'editorScript' => 'file:./index.js', 'viewScriptModule' => 'file:./view.js' ),
	'other'   => array( 'name' => 'demo/other', 'viewScriptModule' => 'file:../counter/view.js' ),
);
