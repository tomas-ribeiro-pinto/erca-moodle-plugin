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
 *
 * @package   local_course_chatbot
 * @copyright 2025, Tomás Pinto <morato.toms@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once('../../config.php');

// Require user to be logged in
require_login();

// Set JSON header and API action parameter (endpoint)
header('Content-Type: application/json');
$action = required_param('action', PARAM_ALPHA);

// Get chatbot ID and user email from request parameters
// TODO: validation
$chatbot_id = required_param('chatbot_id', PARAM_INT);
$user_email = required_param('user_email', PARAM_EMAIL);

// External API URL
// TODO: Make this configurable in settings
global $api_host;
$api_host = 'http://host.docker.internal:5003'; // Using host.docker.internal to access host machine from Docker container

handle_api_request($action, $chatbot_id, $user_email);

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // timeout for API requests
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
 * Handles the API request based on the action parameter.
 *
 * @param string $action The endpoint action to perform.
 * @param int $chatbot_id The ID of the chatbot.
 * @param string $user_email The email of the user making the request.
 * 
 * @throws Exception If the action is invalid or if the API request fails.
 * @throws Error If a PHP error occurs during processing.
 */
function handle_api_request($action, $chatbot_id, $user_email) {
    try {
        global $api_host;
        switch ($action) {
            case 'history':
                // Get chat history
                $url = $api_host . "/chatbot/{$chatbot_id}/history?user_email={$user_email}";
                $response = make_api_request($url);
                echo $response;
                break;
                
            case 'prompt':
                // Send prompt to chatbot
                $input = json_decode(file_get_contents('php://input'), true);
                $prompt = $input['prompt'] ?? '';
                
                if (empty($prompt)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Prompt is required']);
                    exit;
                }

                $url = $api_host . "/chatbot/{$chatbot_id}/prompt?user_email={$user_email}";
                $data = json_encode(['prompt' => $prompt]);
                $response = make_api_request($url, 'POST', $data);
                echo $response;
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