<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *  This file handles local requests and routes them to the external chatbot API.
 *  It acts as a bridge between the Moodle platform and the chatbot service.
 *  This file was written with GitHub Copilot aid.
 *
 * @package   local_course_chatbot
 * @copyright 2025, Tomás Pinto <morato.toms@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once('../../config.php');

// Require user to be logged in
require_login();

// Set API action parameter (endpoint)
$action = required_param('action', PARAM_ALPHA);

// Only set JSON header for non-streaming actions
if ($action !== 'prompt') {
    header('Content-Type: application/json');
}

// Get chatbot ID and user email from request parameters
// TODO: validation
$chatbot_id = required_param('chatbot_id', PARAM_INT);

// External API URL
// TODO: Make this configurable in settings
global $api_host;
$api_host = 'http://host.docker.internal:5003'; // Using host.docker.internal to access host machine from Docker container

handle_api_request($action, $chatbot_id);

/**
 * Function to make HTTP requests to external API using cURL
 * 
 * @param string $url The URL to send the request to
 * @param string $method The HTTP method (GET or POST)
 * @param mixed $data The data to send in the request body (for POST requests) in JSON format
 * @return string The response from the API
 * 
 * @throws Exception If the request fails or returns an error code
 */
function make_api_request($url, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); // timeout for API requests
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    // If the method is POST, set the appropriate options
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data)
            ]);
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($error)) {
        throw new Exception("API request failed: " . $error);
    }
    
    if ($http_code >= 400) {
        throw new Exception("API returned error code: " . $http_code);
    }
    
    return $response;
}

/**
 * Function to make HTTP requests to external API using cURL with SSE streaming support
 * 
 * @param string $url The URL to send the request to
 * @param string $method The HTTP method (GET or POST)
 * @param mixed $data The data to send in the request body (for POST requests) in JSON format
 * 
 * @throws Exception If the request fails or returns an error code
 */
function make_sse_api_request($url, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, 'handle_sse_chunk');
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Longer timeout for streaming
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 128); // Small buffer for real-time streaming
    
    // If the method is POST, set the appropriate options
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data),
                'Accept: text/event-stream'
            ]);
        }
    }
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($result === false || !empty($error)) {
        // Send error as SSE event
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'API request failed: ' . $error]) . "\n\n";
        flush();
        return;
    }
    
    if ($http_code >= 400) {
        // Send error as SSE event
        echo "event: error\n";
        echo "data: " . json_encode(['error' => 'API returned error code: ' . $http_code]) . "\n\n";
        flush();
        return;
    }
    
    // Send completion event
    echo "event: done\n";
    echo "data: {\"status\": \"complete\"}\n\n";
    flush();
}

/**
 * Callback function to handle streaming chunks from the API
 * 
 * @param resource $ch cURL handle
 * @param string $chunk The chunk of data received
 * @return int The number of bytes processed
 */
function handle_sse_chunk($ch, $chunk) {
    // Check if this is an SSE formatted chunk
    if (strpos($chunk, 'data: ') === 0) {
        // Forward the SSE chunk directly
        echo $chunk;
    } else {
        // Format non-SSE data as SSE
        $lines = explode("\n", $chunk);
        foreach ($lines as $line) {
            if (!empty(trim($line))) {
                echo "data: " . trim($line) . "\n\n";
            }
        }
    }
    
    // Flush output immediately for real-time streaming
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
    
    return strlen($chunk);
}

/**
 * Handles the API request based on the action parameter.
 *
 * @param string $action The endpoint action to perform.
 * @param int $chatbot_id The ID of the chatbot.
 * @param string $user_email The email of the user making the request.
 * @param string $user_name The name of the user making the request.
 *
 * @throws Exception If the action is invalid or if the API request fails.
 * @throws Error If a PHP error occurs during processing.
 */
function handle_api_request($action, $chatbot_id) {
    try {
        global $api_host;

        $input = json_decode(file_get_contents('php://input'), true);
        $prompt = $input['prompt'] ?? '';
        $user_email = $input['user_email'] ?? '';
        $user_name = $input['user_name'] ?? '';

        switch ($action) {
            case 'history':
                // Get chat history
                $url = $api_host . "/api/chatbot/{$chatbot_id}/history";
                $data = json_encode(['user_email' => $user_email, 'user_name' => $user_name]);
                $response = make_api_request($url, 'POST', $data);
                echo $response;
                break;
                
            case 'prompt':
                // Send prompt to chatbot with SSE streaming
                
                if (empty($prompt)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Prompt is required']);
                    exit;
                }

                // Set SSE headers
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no'); // Disable nginx buffering

                $url = $api_host . "/api/chatbot/{$chatbot_id}/prompt";
                $data = json_encode(['prompt' => $prompt, 'user_email' => $user_email, 'user_name' => $user_name]);
                make_sse_api_request($url, 'POST', $data);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        http_response_code(500);
        // Add more detailed error information for debugging
        $error_details = [
            'error' => 'Server error: ' . $e->getMessage(),
            'debug_info' => [
                'api_host' => $api_host,
                'action' => $action,
                'chatbot_id' => $chatbot_id,
                'user_email' => $user_email,
                'file' => __FILE__,
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ];
        echo json_encode($error_details);
    } catch (Error $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'PHP Error: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

}