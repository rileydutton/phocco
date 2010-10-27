<?php
//We need the [PHP-Markdown](http://michelf.com/projects/php-markdown/) library to process the syntax for the documentation portions.
require("external/php-markdown/markdown.php");
//Some simple command-line parsing
require("external/class.args.php");

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
 * Given a string of source code, parse out each comment and the code that follows it, and create an individual section for it.
 * Sections take the form:
 *		
 *		{ 
 *			"docs_text": ...,
 *			"docs_html": ...,
 *			"code_text": ...,
 *			"code_html": ...,
 *			"num":       ...
 *		} 
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
	            
	            //If we are in a docblock, we have special handling to try and take PHPDocumentor @properties and turn them into something nicer. We are basically trying to produce something resembling literate-style documentation based on the docblock.
	            if($in_docblock) {
	            	$matches == array();
	            	//See if we have an @property on this line.
	            	preg_match_all("/\s@(.*?)\s(.*)/", $line, $matches);
	            	if(count($matches[0]) != 0) {
	            		$e = explode(" ", $matches[2][0], 3);
						//It's a @param!
	            		if($matches[1][0] == "param") {
	            			$line = "`" . $e[0] . " " . $e[1] . "` " . $e[2];
	            			$type = "param";
	            			$header = "Takes ";
	            		}
	            		//It's a @return!
	            		else if($matches[1][0] == "return") {
	            			$line = "`" . $e[0] . " " . $e[1] . "` " . $e[2];
	            			$type = "return";
	            			$header = "Returns ";
	            		}
	            		//It's something we don't know how to handle...
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
	// stdin, stdout, stderror
	$spec = array(
	   0 => array("pipe", "r"),
	   1 => array("pipe", "w"),
	   2 => array("pipe", "w")
	);
	
	$process = proc_open($cmd, $spec, $pipes);
	
	if (is_resource($process)) {
			
	    fwrite($pipes[0], $code);
	    fclose($pipes[0]);
	    //Check to see if we were actually able to use pygmentize
	    $errors = stream_get_contents($pipes[2]);
	    fclose($pipes[2]);
	    if(strstr($errors, "command not found")) {
	    	//Pygmentize isn't available, fall back on the web service.
	    	print("Using webservice...\n");
	    	//Use the excellent webservice provided by [Flowcoder](http://flowcoder.com/) to give us access to Pygments even 
	    	//though we don't have it installed.
	    	$postdata = http_build_query(
			    array(
			        'code' => $code,
			        'lang' => $language["name"]
			    )
			);
			
			$opts = array('http' =>
			    array(
			        'method'  => 'POST',
			        'header'  => 'Content-type: application/x-www-form-urlencoded',
			        'content' => $postdata
			    )
			);
			
			$context  = stream_context_create($opts);
			//We use file_get_contents instead of cURL because it's more likely to be installed by default.
			$results = file_get_contents('http://pygments.appspot.com/', false, $context);
			
	    }
	    
	    else {
	    	//We were able to use the pygmentize command on the local machine.
			print("Using pygmentize...\n");
		    $results = stream_get_contents($pipes[1]);
		    fclose($pipes[1]);
		
		    $return_value = proc_close($process);
		}
	}
	
	$highlight_start = "<div class=\"highlight\"><pre>";
	$highlight_end = "</pre></div>";
	
	$fragments = preg_split($language["divider_html"], $results);
	//Process the code and documentation for each section.
	foreach($sections as $i=>$section) {
		$sections[$i]["code_html"] = $highlight_start . $fragments[$i] . $highlight_end;
        $sections[$i]["docs_html"] = Markdown($sections[$i]["docs_text"]);
        $sections[$i]["num"] = $i;
	}
	
	return $sections;

}

/**
 * Generate our final HTML file based on the processing we've done. Write out the HTML file.
 * 
 * @param string $filename
 * @param array $sections
 * @return void
 */
function generate_html($filename, $sections) {

	global $sources;
	global $options;

	if(!is_dir($options["outputdir"]))
		mkdir($options["outputdir"]);
	
	$e = explode(".", $filename);
	$basename = $e[0];
	
	$title = $basename;

	ob_start();
	include("template.php");
	$compiled = ob_get_clean();
	
	$file = fopen($options["outputdir"] . "/" . $basename . ".html", "w") or die("Coudn't open output file!");
	fwrite($file, $compiled);
	fclose($file);

}

/**
 * A list of the languages that Phocco supports, which is a subset of the languages Pygment supports.
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

global $options;
global $sources;

$sources = array();
$args = new Args();

//Default options
$options = array(
	"outputdir" => "docs",
);

if($outputdir = $args->flag("o"))
	$options["outputdir"] = $outputdir;

if(count($args->args) == 0) {
	print("You must supply a filename to continue.\n");
}

foreach($args->args as $filename) {
	$e = explode(".", $filename);
	$basename = $e[0];
	$sources[] = $basename;
}

foreach($args->args as $filename) {
	generate_documentation($filename);
}