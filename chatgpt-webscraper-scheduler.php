<?php
/*
Plugin Name: ChatGPT Web Scraper Scheduler
Description: Automatically searches for URLs based on a topic, scrapes content, rewrites with ChatGPT, and creates WordPress posts over a set number of days.
Version: 1.4
Author: CodyBrackets
*/

add_action('admin_menu', function () {
    add_menu_page('GPT Web Scraper', 'GPT Web Scraper', 'manage_options', 'gpt-web-scraper', 'gpt_web_scraper_page');
    add_options_page('ChatGPT Settings', 'ChatGPT Settings', 'manage_options', 'chatgpt-webscraper-settings', 'chatgpt_settings_page');
});

add_filter('plugin_action_links_chatgpt-webscraper-scheduler/chatgpt-webscraper-scheduler.php', function ($links) {
    $settings_link = '<a href="options-general.php?page=chatgpt-webscraper-settings">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

function gpt_web_scraper_page()
{
    ?>
    <div class="wrap">
        <h1>Auto Content Generator with ChatGPT</h1>
        <?php
        $saved = get_option('chatgpt_scrape_schedule');
        if ($saved): ?>
            <div style="background:#f8f9fa;padding:15px;margin:15px 0;border-left:4px solid #2271b1;">
                <strong>📌 Current Schedule:</strong><br>
                <ul style="margin: 8px 0 0 16px;">
                    <li><strong>Topic:</strong> <?= esc_html($saved['topic']) ?></li>
                    <li><strong>Total Days:</strong> <?= intval($saved['total_days']) ?></li>
                    <li><strong>URLs/Day:</strong> <?= intval($saved['urls_per_day']) ?></li>
                    <li><strong>Started:</strong> <?= esc_html($saved['start_date']) ?></li>
                    <li><strong>Current Day:</strong> <?= intval($saved['current_day']) ?> / <?= intval($saved['total_days']) ?>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post">
            <h2>Topic Scheduler</h2>
            <input type="text" name="topic" placeholder="e.g. Bike Events in Makati" style="width:60%;" required><br><br>
            <label>Days to run:</label> <input type="number" name="days" value="2" min="1" max="30">
            <label>URLs/day:</label> <input type="number" name="urls_per_day" value="5" min="1" max="10">
            <br><br>
            <input type="submit" name="schedule_topic" class="button button-secondary" value="Start Scheduled Posting">
        </form>

        <h2 style="margin-top:40px;">Manual Run</h2>
        <button id="run-now" class="button button-primary">Run Now Manually</button>
        <div id="status-box" style="margin-top:20px;"></div>
        <div id="progressbar"
            style="width:100%;background:#ccc;height:20px;border-radius:3px;overflow:hidden;margin-top:10px;">
            <div id="progress-fill" style="height:100%;width:0%;background:#2271b1;"></div>
        </div>

        <script>
            document.getElementById("run-now").addEventListener("click", function () {
                const statusBox = document.getElementById("status-box");
                const progressFill = document.getElementById("progress-fill");
                statusBox.innerHTML = "Running scraper...";
                progressFill.style.width = "0%";

                fetch(ajaxurl, {
                    method: "POST",
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: "gpt_scraper_run_now_ajax" })
                })
                    .then(response => response.json())
                    .then(data => {
                        const total = data.total;
                        let count = 0;
                        statusBox.innerHTML = "";

                        function processNext() {
                            if (count >= total) {
                                statusBox.innerHTML += "<p>✅ All URLs processed.</p>";
                                return;
                            }

                            const url = data.urls[count];
                            statusBox.innerHTML += `<p>🔄 Scraping: ${url}</p>`;

                            fetch(ajaxurl, {
                                method: "POST",
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({ action: "gpt_scraper_process_url", url: url })
                            })
                                .then(r => r.json())
                                .then(result => {
                                    statusBox.innerHTML += `<p style="color:green;">✅ ${result.message}</p>`;
                                    count++;
                                    progressFill.style.width = Math.round((count / total) * 100) + "%";
                                    processNext();
                                })
                                .catch(err => {
                                    statusBox.innerHTML += `<p style="color:red;">❌ Error on ${url}</p>`;
                                    count++;
                                    processNext();
                                });
                        }

                        processNext();
                    });
            });
        </script>
        <?php
        if (isset($_POST['schedule_topic'])) {
            $topic = sanitize_text_field($_POST['topic']);
            $days = intval($_POST['days']);
            $urls_per_day = intval($_POST['urls_per_day']);

            $data = [
                'topic' => $topic,
                'total_days' => $days,
                'urls_per_day' => $urls_per_day,
                'start_date' => date('Y-m-d'),
                'current_day' => 0,
            ];

            update_option('chatgpt_scrape_schedule', $data);

            if (!wp_next_scheduled('chatgpt_scheduled_scrape')) {
                wp_schedule_event(time() + 5, 'daily', 'chatgpt_scheduled_scrape');
            }

            echo "<p style='color:green;'>Scheduled task created. Will auto-run for $days days.</p>";
        }
}

// AJAX: Get list of URLs from ChatGPT
add_action('wp_ajax_gpt_scraper_run_now_ajax', function () {
    $job = get_option('chatgpt_scrape_schedule');
    $topic = $job['topic'] ?? 'Run events in Makati';
    $count = $job['urls_per_day'] ?? 3;
    $urls = gpt_find_urls_via_chatgpt($topic, $count);
    wp_send_json(['urls' => $urls, 'total' => count($urls)]);
});

// AJAX: Process one URL
add_action('wp_ajax_gpt_scraper_process_url', function () {
    $url = esc_url_raw($_POST['url']);
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        wp_send_json_error("Failed to fetch: $url");
    }

    preg_match('/<title>(.*?)<\\/title>/', wp_remote_retrieve_body($response), $matches);
    $title = $matches[1] ?? 'Untitled';
    $body = wp_remote_retrieve_body($response);
    $content_raw = wp_strip_all_tags($body);
    $content = gpt_rewrite_content($content_raw);

    if ($content) {
        wp_insert_post([
            'post_title' => sanitize_text_field($title),
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'post',
        ]);
        wp_send_json_success("Saved draft: " . esc_html($title));
    } else {
        wp_send_json_error("Failed to rewrite content");
    }
});

// GPT calls
function gpt_rewrite_content($text)
{
    $api_key = get_option('chatgpt_api_key');
    if (!$api_key) {
        error_log("[ChatGPT Rewrite] Missing API key.");
        return false;
    }

    if (strlen($text) < 100) {
        error_log("[ChatGPT Rewrite] Skipped: Text too short for meaningful rewrite.");
        return false;
    }

    $prompt = "Rewrite this content for a blog post. Keep it clear and concise:\n\n" . substr($text, 0, 3000);

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.7,
        ])
    ]);

    if (is_wp_error($response)) {
        error_log("[ChatGPT Rewrite] HTTP error: " . $response->get_error_message());
        return false;
    }

    $body_json = wp_remote_retrieve_body($response);
    error_log("[ChatGPT Rewrite] Raw response: " . $body_json);

    $body = json_decode($body_json, true);
    $reply = $body['choices'][0]['message']['content'] ?? '';

    if (empty(trim($reply))) {
        error_log("[ChatGPT Rewrite] Empty reply from GPT.");
        return false;
    }

    return $reply;
}


function gpt_find_urls_via_chatgpt($topic, $count = 2)
{
    $api_key = get_option('chatgpt_api_key');
    if (!$api_key) {
        error_log("[ChatGPT Scraper] Missing API key.");
        return [];
    }

    $prompt = 'Give me ' . $count . ' real and relevant https:// links only, about the topic: "' . $topic . '". Respond with one link per line. Do not include any commentary.';

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
        ])
    ]);

    if (is_wp_error($response)) {
        error_log("[ChatGPT Scraper] HTTP error: " . $response->get_error_message());
        return [];
    }

    $body_json = wp_remote_retrieve_body($response);
    error_log("[ChatGPT Scraper] Raw response: " . $body_json);

    $body = json_decode($body_json, true);
    $text = $body['choices'][0]['message']['content'] ?? '';

    error_log("[ChatGPT Scraper] GPT content: " . $text);

    preg_match_all('/https?:\\/\\/[^\s)"]+/', $text, $matches);
    $urls = array_slice($matches[0], 0, $count);

    // Optional fallback: only use when GPT returns empty
    if (empty($urls)) {
        error_log("[ChatGPT Scraper] GPT returned no URLs. Using fallback links.");
        return [
            'https://mb.com.ph/2023/08/15/greenways-bike-paths-makati',
            'https://bikeph.org/events/makati-bikefest-2024',
            'https://news.abs-cbn.com/life/2023/10/05/bike-tour-around-makati'
        ];
    }

    return $urls;
}


// Settings page
function chatgpt_settings_page()
{
    ?>
        <div class="wrap">
            <h1>ChatGPT API Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('chatgpt_settings');
                do_settings_sections('chatgpt-webscraper-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
}

add_action('admin_init', function () {
    register_setting('chatgpt_settings', 'chatgpt_api_key');

    add_settings_section('chatgpt_section', 'API Configuration', null, 'chatgpt-webscraper-settings');

    add_settings_field('chatgpt_api_key', 'OpenAI API Key', function () {
        $value = esc_attr(get_option('chatgpt_api_key'));
        echo "<input type='text' name='chatgpt_api_key' value='$value' style='width: 400px'>";
    }, 'chatgpt-webscraper-settings', 'chatgpt_section');
});
?>