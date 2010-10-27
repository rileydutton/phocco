<?php
//We need the PHP-Markdown library to process the syntax for the documentation portions.
require("external/php-markdown/markdown.php");

//A simple function for parsing args from the command line.
//Taken from: [http://pwfisher.com/nucleus/index.php?itemid=45](http://pwfisher.com/nucleus/index.php?itemid=45)
function parseArgs($argv){
    array_shift($argv); $o = array();
    foreach ($argv as $a){
        if (substr($a,0,2) == '--'){ $eq = strpos($a,'=');
            if ($eq !== false){ $o[substr($a,2,$eq-2)] = substr($a,$eq+1); }
            else { $k = substr($a,2); if (!isset($o[$k])){ $o[$k] = true; } } }
        else if (substr($a,0,1) == '-'){
            if (substr($a,2,1) == '='){ $o[substr($a,1,1)] = substr($a,3); }
            else { foreach (str_split(substr($a,1)) as $k){ if (!isset($o[$k])){ $o[$k] = true; } } } }
        else { $o[] = $a; } }
    return $o;
}

/**
 * Generate the documentation. The "main loop", so to speak.
 * 
 * @param string $file (the filename)
 * @return void
 */
function generate_documentation($file) {
	$code = file_get_contents($file);
	$sections = parse($file, $code);   
	$sections = highlight($file, $sections);
	generate_html($file, $sections);
}


/**
 * Take each source file, and turn it into a series of sections of corresponding code blocks and documentation blocks
 * 
 * @param string $source (the filename)
 * @param string $code (the actual contents of the file)
 * @return array $sections
 */
function parse($source, $code) {
	
	$lines = explode("\n", $code);
	$sections = array();
	//Determine the language based on the extension of the file.
	$language = get_language($source);
	//`true` when we are "inside" of a code block, `false` otherwise.
	$has_code = false;
	$docs_text = $code_text = "";
	
	//Check to make sure we aren't treating a bash directive as a comment
	if(substr($lines[0], 0, 2) == "#!")
	    array_pop($lines);
	
	
	//For tracking docblock issues.
	$prev_type = "";
	$type = "";
	$docblock_function_name = "";
	$first_listing = false;
	
	foreach($lines as $line_num=>$line) {
		//This is a check added since we support multiple comment symbols for each language. `true` if any of them have matched.
		$found_symbol = false;
		
		//Support for PHPDocumentor-style Docblocks
	    $docblockmatcher = array("/\*\*", "\*");
	
		foreach(array_merge($docblockmatcher, $language["symbols"]) as $symbol) {
			//See if our symbol is found on this line.
	        if(preg_match("|^\s*" . $symbol . "|", $line)) {
	        	//Symbol has been found, but are we in a code block? If so, end this **section**
	            if($has_code) {
	                $sections[] = array(
			            "docs_text" => $docs_text,
			            "code_text" => $code_text
			        );
	                $has_code = false;
	                $docs_text = $code_text = '';
	            }
	            
	            //If we are in a docblock, we have special handling to try and take PHPDocumentor @properties and turn them into something nicer.
	            if($in_docblock) {
	            	$matches == array();
	            	preg_match_all("/\s@(.*?)\s(.*)/", $line, $matches);
	            	if(count($matches[0]) != 0) {
	            		$e = explode(" ", $matches[2][0], 3);

	            		if($matches[1][0] == "param") {
	            			$line = "`" . $e[0] . " " . $e[1] . "` " . $e[2];
	            			$type = "param";
	            			$header = "Takes ";
	            		}
	            		else if($matches[1][0] == "return") {
	            			$line = "`" . $e[0] . " " . $e[1] . "` " . $e[2];
	            			$type = "return";
	            			$header = "Returns ";
	            		}
	            		else {
	            			$type = "";
	            			$header = "";
	            		}
	            		
	            		if($prev_type != $type)
		            		$first = true;	  
		            	else 
		            		$first = false;
	            		
	            		if(!$first) {
	            			$line = " and " . $line;
	            		}
	            		
	            		if($first) {
	            			if($prev_type != "")
	            				$header = ". " . $header;
		            		$line = $header . $line;
		            	}
		            	$prev_type = $type;
	            		
	            	}
	            	else {
	            		$type = "";
	            		$header = "";
	            	}
	            }
	            
	            
	            $line = preg_replace("|^\s*" . $symbol . "|", "", $line) . "\n";
	            
	            //Determine if we are starting a docblock.
	            if($symbol == $docblockmatcher[0]) {
	            	$in_docblock = true;
	            	//Skip ahead until we find the name of this function, if possible.
	            	$fmatch = array();
	            	for($k = $line_num; $k < count($lines); $k++) {
	            		if(preg_match("/function\s(.*)\(/", $lines[$k], $fmatch)) {
	            			$docblock_function_name = $fmatch[1];
	            			$line = "**` " . $docblock_function_name . "`**\n\n ";
	            			break;
	            		}
	            		//We've encountered the next docblock without finding a function. Abort.
	            		if($line == "/**\n")
	            			break; 
	            		$docblock_function_name = "";
	            	}
	            }
	            //Are we ending a docblock?
	            else if($symbol == $docblockmatcher[1]) {
	            	if($line == "/\n") {
	            		$in_docblock = false;
	            		$line = ".";
	            		$prev_type = "";
	            	}
	            }
	            
	            $docs_text .= $line;
	            
	            $found_symbol = true;
	            
	            break;
	        }
	   }
	   //If `found_symbol` is false, then this must be the beginning (or a continution) of a code block.
	   if(!$found_symbol) {
	       $has_code = true;
	       $code_text .= $line . "\n";
	   }
	}
	
	$sections[] = array(
	        "docs_text" => $docs_text,
	        "code_text" => $code_text
	    );
	
	return $sections;

}

/**
 * Highlight each section, using Pygment for the code, and Markdown for the documentation.
 * 
 * @param string $filename
 * @param array $sections
 * @return array $sections
 */
function highlight($filename, $sections) {

	$language = get_language($filename);
	$code = "";

	foreach($sections as $section) {
		$code .= $section["code_text"] . $language["divider_text"];
	}
	
	//Use the pygmentize command if it's available.
	
	$cmd = "pygmentize -l " . $language["name"] . " -f html";
	
	$spec = array(
	   0 => array("pipe", "r"),
	   1 => array("pipe", "w")
	);
	
	$process = proc_open($cmd, $spec, $pipes);
	
	if (is_resource($process)) {
			
	    fwrite($pipes[0], $code);
	    fclose($pipes[0]);
	
	    $results = stream_get_contents($pipes[1]);
	    fclose($pipes[1]);
	
	    $return_value = proc_close($process);
	}
	
	$highlight_start = "<div class=\"highlight\"><pre>";
	$highlight_end = "</pre></div>";
	
	$fragments = preg_split($language["divider_html"], $results);
	
	foreach($sections as $i=>$section) {
		$sections[$i]["code_html"] = $highlight_start . $fragments[$i] . $highlight_end;
        $sections[$i]["docs_html"] = Markdown($sections[$i]["docs_text"]);
        $sections[$i]["num"] = $i;
	}
	
	return $sections;

}

/**
 * Generate our final HTML file based on the processing we've done.
 * 
 * @param string $filename
 * @param array $sections
 * @return void
 */
function generate_html($filename, $sections) {

	global $sources;

	if(!is_dir("docs"))
		mkdir("docs");
	
	$e = explode(".", $filename);
	$basename = $e[0];
	
	$title = $basename;

	ob_start();
	include("template.php");
	$compiled = ob_get_clean();
	
	$file = fopen("docs/" . $basename . ".html", "w") or trigger_error("Coudn't open output file!");
	fwrite($file, $compiled);
	fclose($file);

}

/**
 * A list of the languages that Pocco supports, which is a subset of the languages Pygment supports.
 *
 * Add more languages here!
 */
global $languages;
$languages = array(

	".php" => array(
		"name" => "php",
		"symbols" => array(
			"#",
			"//"
		)
	),
	".py" => array(
		"name" => "python",
		"symbols" => array(
			"#",
		)
	),

);


/**
 * Get the name and symbols of the language we're working with, based on the file extension.
 * 
 * @param string $filename
 * @return array $language
 */
function get_language($filename) {
	global $languages;
	$e = explode(".", $filename);
	$extension = "." . $e[count($e) - 1];
	
	if(!isset($languages[$extension])) {
		print("Could not determine language for extension $extension.\n");
		die();
	}
	
	$l = $languages[$extension];

    // The dividing token we feed into Pygments, to delimit the boundaries between
    // sections.
    $l["divider_text"] = "\n" . $l["symbols"][0] . "DIVIDER\n";

    // The mirror of `divider_text` that we expect Pygments to return. We can split
    // on this to recover the original sections.
    $l["divider_html"] = '/\n*?<span class="c[1]?">' . $l["symbols"][0] . 'DIVIDER<\/span>\n*?/s';
    
    return $l;
        
}


$args = parseArgs($_SERVER['argv']);

if(!isset($args[0])) {
	print("You must supply a filename to continue.\n");
}

global $sources;

$sources = array();

foreach($args as $filename) {
	$e = explode(".", $filename);
	$basename = $e[0];
	$sources[] = $basename;
}

foreach($args as $filename) {
	generate_documentation($filename);
}