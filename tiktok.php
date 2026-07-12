<?php
// ======================================================================
// 🧠 CORE PHP LOGIC FUNCTION
// ======================================================================

/**
 * Resolves a TikTok share URL server-side to extract the raw .mp4 media URL.
 * (This function is identical to the previous PHP example)
 *
 * @param string $shareUrl The full TikTok share URL.
 * @return array An associative array containing 'success' (bool) and 'url' (string|null).
 */
function getTikTokMp4Url(string $shareUrl): array {
    // --- 1. Fetch the HTML content of the page using cURL ---
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $shareUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    
    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $htmlContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        return ['success' => false, 'url' => "cURL Error: " . curl_error($ch)];
    }

    if ($httpCode >= 400) {
        return ['success' => false, 'url' => "HTTP Error! Status Code: {$httpCode}"];
    }

    curl_close($ch);

    // --- 2. Search the HTML for the embedded JSON data using Regex ---
    $jsonDataString = null;
    $patternLdJson = '/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/s';
    
    if (preg_match($patternLdJson, $htmlContent, $matches)) {
        $jsonDataString = $matches[1];
    } else {
        $patternFallback = '/\{"videoData":\s*(\{.*?\})\s*\}/s';
        if (preg_match($patternFallback, $htmlContent, $matches)) {
            $jsonDataString = $matches[1];
        }
    }

    if (!$jsonDataString) {
        return ['success' => false, 'url' => "No recognizable metadata JSON found on the page."];
    }

    // --- 3. Parse the JSON data ---
    $data = json_decode($jsonDataString, true);

    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'url' => "JSON Decode Error: " . json_last_error_msg()];
    }

    // --- 4. Navigate the structure to find the MP4 URL ---
    if (isset($data['videoData']['videos']) && is_array($data['videoData']['videos'])) {
        foreach ($data['videoData']['videos'] as $video) {
            if (isset($video['url']) && is_string($video['url']) && str_ends_with($video['url'], '.mp4')) {
                return ['success' => true, 'url' => $video['url']]; // Success!
            }
        }
    }

    return ['success' => false, 'url' => "Found JSON data, but could not locate the direct .mp4 URL in the expected structure."];
}


// ======================================================================
// 🌐 PHP HANDLER (Handles form submission)
// ======================================================================

$result = null; // Variable to hold the result before sending it to JS/HTML
$message = "Enter a TikTok Share URL above and click 'Get MP4 URL'.";

if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['tiktok_url'])) {
    $submittedUrl = trim($_POST['tiktok_url']);
    
    // Call the core function
    $result = getTikTokMp4Url($submittedUrl);
    
    if ($result['success']) {
        $message = "✅ Success! MP4 URL found.";
    } else {
        $message = "❌ Failure. ";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok MP4 URL Extractor</title>
    <!-- 🎨 CSS Styling -->
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 600px;
            text-align: center;
        }
        h1 {
            color: #ff2d55; /* TikTok Red */
            margin-bottom: 10px;
        }
        p.subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 25px;
        }
        input[type="url"] {
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input[type="url"]:focus {
            border-color: #ff2d55;
            outline: none;
        }
        button {
            padding: 12px 25px;
            background-color: #ff2d55;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 17px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
        }
        button:hover {
            background-color: #e6284a;
            transform: translateY(-2px);
        }
        #resultArea {
            padding: 20px;
            border: 2px dashed #ccc;
            border-radius: 10px;
            min-height: 100px;
            text-align: left;
            word-wrap: break-word; /* Ensures long URLs wrap */
        }
        .result-message {
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 1.1em;
        }
        .success { color: #28a745; } /* Green */
        .failure { color: #dc3545; } /* Red */
    </style>
</head>
<body>

<div class="container">
    <h1>🎬 TikTok MP4 URL Extractor</h1>
    <p class="subtitle">Paste any TikTok share link below to get the direct .mp4 stream URL.</p>

    <!-- The form submits data via POST to this same index.php file -->
    <form id="urlForm" action="" method="POST">
        <input type="url" name="tiktok_url" placeholder="e.g., https://vm.tiktok.com/ZJ9yXw/" required 
               value="<?php echo htmlspecialchars($_POST['tiktok_url'] ?? ''); ?>">
        <button type="submit" id="submitButton">Get MP4 URL</button>
    </form>

    <!-- Display Area -->
    <div id="resultArea">
        <p class="result-message <?php echo (isset($result) && $result['success']) ? 'success' : 'failure'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </p>
        <?php if ($result): ?>
            <?php if ($result['success']): ?>
                <p><strong>Raw MP4 URL:</strong> <a href="<?php echo htmlspecialchars($result['url']); ?>" target="_blank"><?php echo htmlspecialchars($result['url']); ?></a></p>
            <?php else: ?>
                <p style="font-size: 0.9em; color: #888;">Details: <?php echo htmlspecialchars($result['url']); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<!-- 💻 JavaScript for AJAX Submission -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('urlForm');
    const resultArea = document.getElementById('resultArea');
    const submitButton = document.getElementById('submitButton');

    // Function to update the UI based on PHP's initial state (if a URL was already submitted)
    function initializeUI() {
        // Check if PHP has already processed a submission and set the initial message/state
        const initialMessageElement = resultArea.querySelector('.result-message');
        if (initialMessageElement) {
            const isSuccess = initialMessageElement.classList.contains('success');
            
            // Update button text based on whether we are showing a success or failure state initially
            submitButton.textContent = isSuccess ? '✅ URL Found!' : 'Get MP4 URL';
        }
    }

    // Function to handle the AJAX submission when the user clicks the button
    form.addEventListener('submit', function(e) {
        e.preventDefault(); // Stop the default form reload behavior
        
        const urlInput = document.querySelector('input[name="tiktok_url"]').value;
        
        // Update UI immediately to show loading state
        resultArea.innerHTML = `
            <p class="result-message success">⏳ Fetching URL... Please wait...</p>
            <div style="height: 50px;"></div> <!-- Spacer -->
        `;
        submitButton.disabled = true;
        submitButton.textContent = 'Processing...';

        // Use the Fetch API to send data to THIS SAME PHP file (index.php)
        fetch(window.location.pathname, { // Sends request back to index.php
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `tiktok_url=${encodeURIComponent(urlInput)}`
        })
        .then(response => response.text()) // Get the entire HTML response text back
        .then(htmlText => {
            // Since PHP returns a full HTML page, we parse it to find our result data
            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlText, 'text/html');
            
            // Find the elements that contain the final status and URL from the PHP output
            const messageElement = doc.querySelector('.result-message');
            const urlLinkElement = doc.querySelector('a[href*=".mp4"]');

            if (messageElement) {
                messageElement.textContent = messageElement.textContent; // Keep the text content
                // Update class based on success/failure status set by PHP
                messageElement.className = `result-message ${messageElement.classList.contains('success') ? 'success' : 'failure'}`;
            }

            if (
// ... [Continuation from previous block] ...
        .then(htmlText => {
            // Since PHP returns a full HTML page, we parse it to find our result data
            const parser = new DOMParser();
            const doc = parser.parseFromString(htmlText, 'text/html');
            
            // Find the elements that contain the final status and URL from the PHP output
            const messageElement = doc.querySelector('.result-message');
            const urlLinkElement = doc.querySelector('a[href*=".mp4"]');

            if (messageElement) {
                messageElement.textContent = messageElement.textContent; // Keep the text content
                // Update class based on success/failure status set by PHP
                messageElement.className = `result-message ${messageElement.classList.contains('success') ? 'success' : 'failure'}`;
            }

            if (urlLinkElement) {
                const rawUrl = urlLinkElement.getAttribute('href');
                // Display the URL clearly in a paragraph below the message
                let urlParagraph = doc.querySelector('p:has(a[href*=".mp4"])'); // Select the parent <p> tag containing the link
                if (urlParagraph) {
                    urlParagraph.innerHTML = `<strong style="color: #333;">Raw MP4 URL:</strong> <a href="${rawUrl}" target="_blank">${rawUrl}</a>`;
                }
            } else if (!messageElement.textContent.includes("Failure")) {
                 // If no link is found but the message isn't explicitly a failure, something went wrong in structure
                 const fallbackP = doc.querySelector('p:not(.result-message)');
                 if(fallbackP) fallbackP.innerHTML = `<strong>Raw MP4 URL:</strong> (Could not find specific link)`;
            }

        })
        .catch(error => {
            // Handle network errors (e.g., server is down, CORS issue if running on a different domain)
            console.error('Fetch Error:', error);
            resultArea.innerHTML = `
                <p class="result-message failure">🚨 Network Error!</p>
                <p style="font-size: 0.9em; color: #888;">Could not connect to the server or process the response.</p>
            `;
        })
        .finally(() => {
            // Re-enable button and reset state regardless of success or failure
            submitButton.disabled = false;
            submitButton.textContent = 'Get MP4 URL';
        });
    });

    // Run initialization when the page first loads
    initializeUI();
});
</script>

</body>
</html>
