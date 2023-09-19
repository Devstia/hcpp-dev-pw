<?php
// Define the folder path where your text files are located
$folderPath = __DIR__ . '/queue';

// Initialize an array to store the message objects
$messages = [];

// Get a list of files in the folder
$files = scandir($folderPath);

// Loop through each file in the folder
foreach ($files as $file) {
    // Check if the file is a text file (ends with .txt)
    if (pathinfo($file, PATHINFO_EXTENSION) === 'txt') {
        // Extract the id and title from the filename
        preg_match('/^(\d+)_([\w\s]+)\.txt$/', $file, $matches);

        if (count($matches) === 3) {
            $id = (int)$matches[1];
            $title = $matches[2];
            // Read the contents of the file
            $message = file_get_contents($folderPath . '/' . $file);

            // Create a message object
            $messageObject = [
                "id" => $id,
                "title" => $title,
                "message" => $message
            ];

            // Add the message object to the array
            $messages[] = $messageObject;
        }
    }
}

// Sort the messages array by id in ascending order
usort($messages, function ($a, $b) {
    return $a['id'] - $b['id'];
});

// Encode the sorted array as JSON
$json = json_encode($messages, JSON_PRETTY_PRINT);

// Output the JSON
header('Content-Type: application/json');
echo $json;
