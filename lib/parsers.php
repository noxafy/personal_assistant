<?php

require_once __DIR__ . '/../lib/utils.php';

// Require PDF parser and Readbility libraries
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * This function downloads a PDF from a URL, extracts the text using PdfParser and returns it.
 *
 * @param string $url The URL of the PDF file to download
 * @return string The extracted text from the PDF or error message
 */
function text_from_pdf($url) {
    // Generate a temporary file name
    $temp_file = tempnam(sys_get_temp_dir(), "pdf_");

    // Download the PDF file
    $file_content = file_get_contents($url);
    if ($file_content === false) {
        return "Error: Could not download the PDF file.";
    }

    // Save the content to the temporary file
    if (file_put_contents($temp_file, $file_content) === false) {
        return "Error: Could not save the PDF file to a temporary location.";
    }

    try {
        // Check if the PDF parser library is available
        if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
            return "Error: PDF Parser library not found.";
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($temp_file);
        $text = $pdf->getText();
    } catch (\Exception $e) {
        return "Error: Could not extract text from PDF: " . $e->getMessage();
    } finally {
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
    }
    return post_process_pdf_text($text);
}

/**
 * Post-processes extracted PDF text to improve readability and consistency
 *
 * @param string $text The raw text extracted from a PDF
 * @return string The improved text after post-processing
 */
 function post_process_pdf_text($text) {
     // Ensure valid UTF-8 (replace invalid sequences) FIRST
     $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

     // Remove control characters except tab (\x09), newline (\x0A), and carriage return (\x0D)
     $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

     // Replace common problematic Unicode characters with ASCII equivalents
     $replace = [
         "\xC2\xA0"    => " ",  // non-breaking space
         "\xE2\x80\x93" => "-", // en-dash
         "\xE2\x80\x94" => "-", // em-dash
         "\xE2\x80\x98" => "'", // left single quote
         "\xE2\x80\x99" => "'", // right single quote
         "\xE2\x80\x9C" => '"', // left double quote
         "\xE2\x80\x9D" => '"', // right double quote
         "\xE2\x80\xA6" => "...", // ellipsis
     ];
     $text = strtr($text, $replace);

     // Normalize newlines, then collapse only spaces (not all whitespace)
     $text = preg_replace('/\s*\n\s*/', "\n", $text);
     $text = preg_replace('/[ ]+/', ' ', $text);

     // Fix hyphenation and spacing
     $text = preg_replace('/(\w+)-\s*\n\s*(\w+)/', '$1$2', $text);
     $text = preg_replace('/\s+([.,;:!?)])/', '$1', $text);
     $text = preg_replace('/([[(])\s+/', '$1', $text);

     return $text;
 }

/**
 * Processes an arXiv link, downloads the TeX source, and returns formatted content
 *
 * @param string $url The arXiv URL or ID
 * @return string The formatted content or error message
 */
function text_from_arxiv_id($arxiv_id) {
    $source = get_arxiv_source($arxiv_id);
    if (has_error($source))
        return $source;

    // Get paper metadata from arXiv API
    $paper_title = "arXiv:$arxiv_id";
    $paper_url = "https://export.arxiv.org/api/query?id_list=$arxiv_id";

    $xml = @simplexml_load_file($paper_url);
    if ($xml && isset($xml->entry->title)) {
        $paper_title = (string)$xml->entry->title;
    }

    return [
        'title' => $paper_title,
        'id' => $arxiv_id,
        'content' => $source
    ];
}

/**
 * Downloads LaTeX source code from arXiv for a given paper ID
 *
 * @param string $arxiv_id The arXiv ID (e.g., "2301.00001")
 * @return string|false The LaTeX source code or false on failure
 */
function get_arxiv_source($arxiv_id) {
    // Clean the ID
    $arxiv_id = preg_replace('/v\d+$/', '', $arxiv_id); // Remove version number if present

    // Construct the URL for the source tarball
    $source_url = "https://arxiv.org/e-print/$arxiv_id";

    // Create temp directory
    $temp_dir = sys_get_temp_dir() . '/arxiv_' . uniqid();
    if (!mkdir($temp_dir, 0755, true)) {
        return "Error: Could not create temporary directory.";
    }

    // Download the source
    $temp_file = "$temp_dir/source.tar.gz";
    if (file_put_contents($temp_file, file_get_contents($source_url)) === false) {
        rmdir($temp_dir);
        return "Error: Could not download arXiv source.";
    }

    // Extract the archive
    $output = [];
    $return_var = 0;
    exec("tar -xzf $temp_file -C $temp_dir", $output, $return_var);
    if ($return_var !== 0) {
        // Try alternate file formats
        exec("tar -xf $temp_file -C $temp_dir", $output, $return_var);
        if ($return_var !== 0) {
            array_map('unlink', glob("$temp_dir/*"));
            rmdir($temp_dir);
            return "Error: Failed to extract arXiv source archive.";
        }
    }

    // Find main TeX file
    $tex_files = glob("$temp_dir/*.tex");
    if (empty($tex_files)) {
        // Look in subdirectories
        $tex_files = glob("$temp_dir/*/*.tex");
        if (empty($tex_files)) {
            array_map('unlink', glob("$temp_dir/*"));
            rmdir($temp_dir);
            return "Error: No TeX files found in the archive.";
        }
    }

    // Prioritize files that might be the main file
    $main_file = $tex_files[0];  // If no main file found, use the first one
    foreach ($tex_files as $file) {
        $content = file_get_contents($file);
        if (strpos($content, '\documentclass') !== false ||
            strpos($content, '\begin{document}') !== false) {
            $main_file = $file;
            break;
        }
    }
    if ($main_file === null) {
        array_map('unlink', glob("$temp_dir/*"));
        rmdir($temp_dir);
        return "Error: Could not identify main TeX file.";
    }

    // Read the file
    $tex_content = file_get_contents($main_file);

    // Clean up
    array_map('unlink', glob("$temp_dir/*"));
    array_map('unlink', glob("$temp_dir/*/*"));
    array_map('rmdir', glob("$temp_dir/*"));
    rmdir($temp_dir);

    // Process the TeX content
    if (!$tex_content)
        return "Error: Could not read TeX content.";
    return clean_tex($tex_content);
}

/**
 * Cleans LaTeX content by extracting the main document body, removing comments and bibliography.
 *
 * @param string $tex_content The raw TeX content
 * @return string The cleaned TeX content
 */
function clean_tex($tex_content) {
    // Extract content between \begin{document} and \end{document}
    if (preg_match('/\\\\begin\s*{\s*document\s*}(.+)\\\\end\s*{\s*document\s*}/s', $tex_content, $matches)) {
        $tex_content = $matches[1];
    }
    // Remove comments
    $tex_content = preg_replace('/(?<!\\\\)%.*$/m', '', $tex_content);
    // Remove thebibliography section
    $tex_content = preg_replace('/\\\\begin\s*{\s*thebibliography\s*}.*?\\\\end\s*{\s*thebibliography\s*}/s', '', $tex_content);
    return $tex_content;
}

function parse_link($link) {
    // Fetch with curl
    $ch = curl_init($link);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: identity', // Request uncompressed responses to avoid unsupported encodings (e.g. Brotli)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);

    // Check for curl errors
    if ($html === false) {
        return "Error: Failed to fetch URL: " . curl_error($ch);
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    // Check for HTTP errors
    if ($http_code >= 400) {
        return "Error: HTTP error $http_code when fetching URL" . ($final_url !== $link ? " (redirected to $final_url)" : "");
    }

    $html_length = strlen($html);
    if ($html_length === 0) {
        return "Error: Empty response from server (HTTP $http_code)";
    }

    if (!class_exists('\\fivefilters\\Readability\\Readability')) {
        return "Error: Readability library not found.";
    }

    // Extract text using Readability
    $configuration = new \fivefilters\Readability\Configuration();
    try {
        $readability = new \fivefilters\Readability\Readability($configuration);
        $readability->parse($html);
        $result = $readability->getContent();

        // Try converting to Markdown if the converter is available
        if (class_exists('\\League\\HTMLToMarkdown\\HtmlConverter')) {
            try {
                $converter = new \League\HTMLToMarkdown\HtmlConverter([
                    'strip_tags' => true,
                    'use_autolinks' => true,
                    'remove_nodes' => 'script style',
                    'hard_break' => true
                ]);
                $result = $converter->convert($result);
            } catch (\Exception $e) {
                // If conversion fails, fall back to strip_tags
                // Log::error("HTML to Markdown conversion failed: " . $e->getMessage());
                $result = strip_tags($result);
            }
        } else {
            // If converter is not available, fall back to strip_tags
            $result = strip_tags($result);
        }

        $result = trim($result);
    } catch (\Exception $e) {
        return "Error: Could not parse link content: " . $e->getMessage()
            . " (HTTP $http_code, response length: $html_length bytes"
            . ($final_url !== $link ? ", redirected to $final_url" : "") . ")";
    }
    return $result;
}

?>
