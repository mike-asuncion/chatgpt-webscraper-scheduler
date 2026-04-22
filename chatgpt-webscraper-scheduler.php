<?php
/*
Plugin Name: ChatGPT Web Scraper Scheduler
Description: Automatically searches for URLs based on a topic, scrapes content, rewrites with ChatGPT, and creates WordPress posts over a set number of days.
Version: 1.5
Author: MikeAsuncion
*/

function getGptModel(): string
{
  return get_option('chatgpt_model') ?: 'gpt-3.5-turbo';
}

//----- ADD CUSTOM URL TO REWRITE
function gpt_custom_urls_page()
{
  ?>
  <div class="wrap">
    <h1>Manual URL Scraper</h1>
    <form method="post" id="custom-url-form">
      <textarea name="custom_urls" rows="10" style="width:100%;" placeholder="Paste one URL per line..."
        required></textarea><br><br>
      <input type="submit" class="button button-primary" value="Start Processing">
    </form>

    <div id="status-box"
      style="display:none; margin-top: 20px; max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;">
    </div>

    <div class="progress" style="height: 25px; margin-top: 10px; display:none;">
      <div id="progress-fill" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar"
        style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
    </div>
  </div>

  <script>
    document.getElementById('custom-url-form').addEventListener('submit', function (e) {
      e.preventDefault();

      const textarea = this.querySelector('textarea[name="custom_urls"]');
      const urls = textarea.value.trim().split("\n").map(u => u.trim()).filter(Boolean);
      if (urls.length === 0) return;

      const statusBox = document.getElementById("status-box");
      const progress = document.querySelector(".progress");
      const progressFill = document.getElementById("progress-fill");
      const button = this.querySelector("input[type='submit']");

      statusBox.innerHTML = "";
      statusBox.style.display = "block";
      progress.style.display = "block";
      button.disabled = true;

      let count = 0;
      const total = urls.length;

      function processNext() {
        if (count >= total) {
          progressFill.classList.remove("bg-info");
          progressFill.classList.add("bg-success");
          progressFill.innerText = "DONE!";
          button.disabled = false;
          return;
        }

        const url = urls[count];
        statusBox.innerHTML += `<p class="mb-0">🔄 Scraping: ${url}</p>`;

        fetch(ajaxurl, {
          method: "POST",
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: "gpt_scraper_process_url",
            url: url
          })
        })
          .then(r => r.json())
          .then(result => {
            if (result.success) {
              statusBox.innerHTML += `<p style="color:green;">✅ ${result.data}</p>`;
            } else {
              statusBox.innerHTML += `<p style="color:red;">❌ ${result.data}</p>`;
            }

            count++;
            const percent = Math.round((count / total) * 100);
            progressFill.style.width = percent + "%";
            progressFill.setAttribute("aria-valuenow", percent);
            progressFill.innerText = percent + "%";

            processNext();
          })
          .catch(() => {
            statusBox.innerHTML += `<p style="color:red;">❌ Error on ${url}</p>`;
            count++;
            processNext();
          });
      }

      processNext();
    });
  </script>
  <?php
}




//----- SEARCH AND REWRITE
add_action('admin_enqueue_scripts', function () {
  wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css');
});

add_action('admin_menu', function () {
  add_menu_page('GPT Web Scraper', 'GPT Web Scraper', 'manage_options', 'gpt-web-scraper', 'gpt_web_scraper_page');
  // add_options_page('ChatGPT Settings', 'ChatGPT Settings', 'manage_options', 'chatgpt-webscraper-settings', 'chatgpt_settings_page');
  add_submenu_page(
    'gpt-web-scraper',
    'Custom URL Scraper',
    'Custom URLs',
    'manage_options',
    'gpt-custom-urls',
    'gpt_custom_urls_page'
  );
  add_submenu_page(
    'gpt-web-scraper',
    'ChatGPT Settings',
    'Settings',
    'manage_options',
    'chatgpt-webscraper-settings',
    'chatgpt_settings_page'
  );

});

add_filter('plugin_action_links_chatgpt-webscraper-scheduler/chatgpt-webscraper-scheduler.php', function ($links) {
  $settings_link = '<a href="options-general.php?page=chatgpt-webscraper-settings">Settings</a>';
  array_unshift($links, $settings_link);
  return $links;
});

function gpt_web_scraper_page()
{
  echo '<div class="wrap"><h1>Auto Content Generator with ChatGPT</h1>';

  // Show saved settings
  $saved = get_option('chatgpt_scrape_schedule');
  if ($saved): ?>
    <div style="background:#f8f9fa;padding:15px;margin:15px 0;border-left:4px solid #2271b1;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;">
        <div>
          <strong>📌 Current Schedule:</strong><br>
          <?php
          $currentPrompt = get_option('chatgpt_prompt_template');
            if ($currentPrompt) {
              echo '<div style="background:#fff3cd;border-left:4px solid #ffeeba;padding:10px;margin:10px 0;">
                <strong>💡 Current Prompt Template:</strong><br>
                <pre style="white-space:pre-wrap;">' . esc_html($currentPrompt) . '</pre>
              </div>';
            }
            ;?>
          <ul style="margin: 8px 0 0 16px;">
            <li><strong>Topic:</strong> <?= esc_html($saved['topic']) ?></li>
            <?php if (!empty($saved['year_start']) && !empty($saved['year_end'])): ?>
              <li><strong>Year Range:</strong> <?= esc_html($saved['year_start']) ?> – <?= esc_html($saved['year_end']) ?>
              </li>
            <?php endif; ?>

            <li><strong>Total Days:</strong> <?= intval($saved['total_days']) ?></li>
            <li><strong>URLs/Day:</strong> <?= intval($saved['urls_per_day']) ?></li>
            <li><strong>Current Day:</strong> <?= intval($saved['current_day']) ?> / <?= intval($saved['total_days']) ?>
            <li><strong>Skip Empty Pages:</strong> <?= !empty($saved['skip_empty_rewrites']) ? 'Yes' : 'No' ?></li>
            <li><strong>Rewrite in Same Language:</strong> <?= !empty($saved['preserve_language']) ? 'Yes' : 'No' ?></li>
          </ul>
        </div>

        <form method="post" onsubmit="return confirm('Are you sure you want to delete the current schedule?');">
          <input type="hidden" name="clear_schedule" value="1">
          <button class="button button-link-delete">🗑 Delete Schedule</button>
        </form>
      </div>
    </div>
  <?php endif;



  // Schedule form
  echo '<div class="card"><form method="post">
    <h2>Topic Scheduler</h2>
    <input type="text" name="topic" placeholder="e.g. Bike Events in BGC, Taguig" style="width:60%;" required><br><br>
    
    <label>Days to run:</label> 
    <input type="number" name="days" value="1" min="1" max="30">

    <label>URLs/day:</label> 
    <input type="number"  value="20" name="urls_per_day" min="1" max="20"><br><br>

    <label>Start Year:</label>
    <select name="year_start">';
  for ($y = date('Y'); $y >= 2000; $y--) {
    echo '<option value="' . $y . '">' . $y . '</option>';
  }
  echo '</select>

    <label style="margin-left: 10px;">End Year:</label>
    <select name="year_end">';
  for ($y = date('Y'); $y >= 2000; $y--) {
    echo '<option value="' . $y . '">' . $y . '</option>';
  }
  echo '</select><br><br>

    <label>
      <input type="checkbox" name="skip_empty_rewrites" value="1">
      Don\'t save post if scraped content is blank or page returns 404
    </label>
    <br>
    <label>
      <input type="checkbox" name="preserve_language" value="1">
      Rewrite in the same language
    </label>
    <br><br>

    <input type="submit" name="schedule_topic" class="button button-secondary" value="Start Scheduled Posting">
</form></div>';




  // Manual run
  echo '<h2 style="margin-top:40px;">Manual Run</h2>
  <button id="run-now" class="button button-primary">Run Now Manually</button>

  <div id="status-box" style="display:none; margin-top:20px; max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;"></div>

  <div class="progress" id="progress-container" style="display:none; margin-top: 10px;">
    <div id="progress-fill" class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
      role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%
    </div>
  </div>';


  // Handle schedule submit
  if (isset($_POST['schedule_topic'])) {
    $topic = sanitize_text_field($_POST['topic']);
    $days = intval($_POST['days']);
    $urls_per_day = intval($_POST['urls_per_day']);

    $preserve_language = isset($_POST['preserve_language']) ? 1 : 0;

    $year_start = sanitize_text_field($_POST['year_start']);
    $year_end = sanitize_text_field($_POST['year_end']);

    $data = [
      'topic' => $topic,
      'total_days' => $days,
      'urls_per_day' => $urls_per_day,
      'start_date' => date('Y-m-d'),
      'current_day' => 0,
      'year_start' => $year_start,
      'year_end' => $year_end,
      'rewrite_same_language' => isset($_POST['rewrite_same_language']) ? 1 : 0,
      'skip_empty_rewrites' => isset($_POST['skip_empty_rewrites']) ? 1 : 0,
      'preserve_language' => $preserve_language,

    ];



    delete_option('chatgpt_scrape_schedule');
    update_option('chatgpt_scrape_schedule', $data);

    if (!wp_next_scheduled('chatgpt_scheduled_scrape')) {
      wp_schedule_event(time() + 5, 'daily', 'chatgpt_scheduled_scrape');
    }

    echo "<h2 style='color:green;'>✅ New schedule saved and old one replaced.</h2><script>location.reload();</script>";
    // echo "<script>location.reload();</script>";
  }

  // Handle clear
  if (isset($_POST['clear_schedule'])) {
    delete_option('chatgpt_scrape_schedule');
    echo "<p style='color:red;'>✅ Schedule cleared. Refreshing...</p><script>location.reload();</script>";

  }

  echo '</div>'; // .wrap

  // JavaScript AJAX runner
  ?>
  <script>
    document.getElementById("run-now").addEventListener("click", function () {
      const runBtn = document.getElementById("run-now");
      const statusBox = document.getElementById("status-box");
      const progressFill = document.getElementById("progress-fill");
      const progressContainer = document.getElementById("progress-container");

      runBtn.disabled = true;
      statusBox.style.display = "block";
      progressContainer.style.display = "block";
      statusBox.innerHTML = "⏳ Running scraper...";
      progressFill.style.width = "0%";
      progressFill.setAttribute('aria-valuenow', 0);
      progressFill.innerText = "0%";

      fetch(ajaxurl, {
        method: "POST",
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: "gpt_scraper_run_now_ajax" })
      })
        .then(response => response.json())
        .then(data => {
          const total = data.total;
          let count = 0;
          const urls = data.urls;

          if (!urls || urls.length === 0) {
            statusBox.innerHTML += "<p style='color:red;'>❌ No URLs returned by GPT.</p>";
            runBtn.disabled = false;
            return;
          }

          statusBox.innerHTML = "";

          function processNext() {
            if (count >= total) {
              statusBox.innerHTML += "<p style='color:green;font-weight:bold;'>✅ DONE!</p>";
              progressFill.style.width = "100%";
              progressFill.setAttribute('aria-valuenow', 100);
              progressFill.innerText = "✅ DONE!";
              runBtn.disabled = false;
              return;
            }


            const url = urls[count];
            statusBox.innerHTML += `<p class="mb-0">🔄 Scraping: ${url}</p>`;

            fetch(ajaxurl, {
              method: "POST",
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({ action: "gpt_scraper_process_url", url: url })
            })
              .then(r => r.json())
              .then(result => {
                if (result.success) {
                  statusBox.innerHTML += `<p style="color:green;">✅ ${result.data}</p>`;
                } else {
                  statusBox.innerHTML += `<p style="color:red;">❌ ${result.data}</p>`;
                }

                count++;
                const percent = Math.round((count / total) * 100);
                progressFill.style.width = percent + "%";
                progressFill.setAttribute('aria-valuenow', percent);
                progressFill.innerText = percent + "%";
                processNext();
              })
              .catch(() => {
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
}

// AJAX: Get URLs
add_action('wp_ajax_gpt_scraper_run_now_ajax', function () {
  $job = get_option('chatgpt_scrape_schedule');
  $topic = $job['topic'] ?? 'Run events in Makati';
  $count = $job['urls_per_day'] ?? 3;
  $urls = gpt_find_urls_via_chatgpt($topic, $count);
  // $urls = [
  //   'https://news.abs-cbn.com/news/2024/12/20/duterte-interview-latest',
  //   'https://www.rappler.com/nation/duterte-latest-speech-philippines/',
  // ];
  wp_send_json(['urls' => $urls, 'total' => count($urls)]);
});

// AJAX: Process each URL
add_action('wp_ajax_gpt_scraper_process_url', function () {
  $url = esc_url_raw($_POST['url']);
  $response = wp_remote_get($url);

  if (is_wp_error($response)) {
    wp_send_json_success("⚠️ Skipped (URL not reachable): ");
    return;
  }

  $body = wp_remote_retrieve_body($response);
  $lower = strtolower($body);

  // Skip if body is too short or contains Cloudflare/error indicators
  if (
    empty($body) ||
    strlen($body) < 500
    //  || 
    // strpos($lower, 'cloudflare ray id') !== false ||
    // strpos($lower, 'access denied') !== false ||
    // strpos($lower, 'error 403') !== false ||
    // strpos($lower, 'blocked from accessing') !== false ||
    // strpos($lower, 'captcha') !== false
  ) {
    wp_send_json_success("⚠️ Skipped (Blocked or invalid page): ");
    return;
  }

  // Basic validation
  if (empty($body) || strlen($body) < 100) {
    wp_send_json_success("⚠️ Skipped (Empty or short content): ");
    return;
  }

  // Check for common error phrases
  $error_patterns = [
    '404 Not Found',
    'Page not found',
    'Oops! It looks like the page',
    'content not available',
    'access denied',
    'this page does not exist',
    '403 Forbidden',
    'you were looking for doesn’t exist'
  ];

  foreach ($error_patterns as $pattern) {
    if (stripos($body, $pattern) !== false) {
      wp_send_json_success("⚠️ Skipped (Error page detected): ");
      return;
    }
  }


  preg_match('/<title>(.*?)<\/title>/', $body, $matches);
  $rawTitle = trim($matches[1] ?? 'Untitled');

  $rewrittenTitle = gpt_rewrite_title($rawTitle);
  $title = $rewrittenTitle ?: $rawTitle;

  $title = wp_strip_all_tags($title);

  if (empty($title) || strlen($title) < 5) {
    wp_send_json_success("⚠️ Skipped (Title too short or missing): ");
    return;
  }

  $forbidden_keywords = [
    '403 forbidden',
    '404',
    '404 Page - 404 Page',
    '404 Page',
    '404 not found',
    'page not found',
    'access denied',
    'untitled',
    'error',
    'invalid page',
    'website unavailable',
    'site unavailable',
    'not found',
    'forbidden',
    'bad gateway',
    '502',
    '503',
    '504',
  ];

  $title_lower = strtolower(trim($title));

  foreach ($forbidden_keywords as $keyword) {
    if (stripos($title_lower, $keyword) !== false) {
      wp_send_json_success("⚠️ Skipped (Error title detected): " . esc_html($title));
      return;
    }
  }



  $content_raw = wp_strip_all_tags($body);
  $job = get_option('chatgpt_scrape_schedule');
  $skip_empty = !empty($job['skip_empty_rewrites']);
  $rewrite_same_lang = !empty($job['rewrite_same_language']);


  // $content = gpt_rewrite_content($content_raw, $job['preserve_language'] ?? false);
  $content = gpt_rewrite_content($content_raw, $rewrite_same_lang);

  // Add check to skip if GPT gives filler response
  if ($skip_empty && (!$content || str_contains($content, 'Oops') || strlen($content) < 200)) {
    wp_send_json_success("⚠️ Skipped (GPT gave no meaningful rewrite): ");
    return;
  }
  if ($content) {
    // Check for existing draft with same title
    $existing_query = new WP_Query([
      'post_type' => 'post',
      'post_status' => 'draft',
      'title' => $title,
      'posts_per_page' => 1,
    ]);

    if ($existing_query->have_posts()) {
      wp_send_json_success("⚠️ Skipped (Duplicate draft already exists): " . esc_html($title));
      return;
    }


    $content_with_source = $content . "\n\n<p><em>Check this link for more: <a href='" . esc_url($url) . "' target='_blank' rel='nofollow noopener'>" . esc_html($url) . "</a></em></p>";

    wp_insert_post([
      'post_title' => sanitize_text_field($title),
      'post_content' => $content_with_source,
      'post_status' => 'draft',
      'post_type' => 'post',
    ]);

    wp_send_json_success("✅ Saved draft: " . esc_html($title));
  } else {
    wp_send_json_success("⚠️ Skipped (GPT rewrite failed): ");
  }
});

//Rewrite title
function gpt_rewrite_title($title)
{
  $api_key = get_option('chatgpt_api_key');
  if (!$api_key || strlen($title) < 5)
    return false;

  $prompt = "Rewrite the following content title to make it more catchy, SEO-friendly, and relevant for a blog post. Keep it short and engaging:\n\n\"$title\"";

  $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
    'headers' => [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $api_key,
    ],
    'body' => json_encode([
      'model' => getGptModel(),
      'messages' => [['role' => 'user', 'content' => $prompt]],
      'temperature' => 0.7,
    ])
  ]);

  $body_json = wp_remote_retrieve_body($response);
  error_log("[ChatGPT Rewrite Title] Raw response: " . $body_json);

  $body = json_decode($body_json, true);
  $reply = $body['choices'][0]['message']['content'] ?? '';
  return trim($reply) ?: false;
}

// Rewrite content
function gpt_rewrite_content($text, $preserve_language = false)
{
  $api_key = get_option('chatgpt_api_key');
  if (!$api_key || strlen($text) < 100)
    return false;

  $language_note = $preserve_language ? "Maintain the original language of the content." : "";
  $prompt = "Rewrite this content for a blog post. Keep it clear and focus on the content. $language_note\n\n" . substr($text, 0, 3000);

  $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
    'headers' => [
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $api_key,
    ],
    'body' => json_encode([
      'model' => getGptModel(),
      'messages' => [['role' => 'user', 'content' => $prompt]],
      'temperature' => 0.7,
    ])
  ]);

  $body_json = wp_remote_retrieve_body($response);
  error_log("[ChatGPT Rewrite] Raw response: " . $body_json);

  $body = json_decode($body_json, true);
  $reply = $body['choices'][0]['message']['content'] ?? '';
  return trim($reply) ?: false;
}


// URL list via GPT
function gpt_find_urls_via_chatgpt($topic, $count = 3, $retryLimit = 2)
{
  $api_key = get_option('chatgpt_api_key');
  if (!$api_key)
    return [];

  $job = get_option('chatgpt_scrape_schedule');
  $year_start = $job['year_start'] ?? date('Y');
  $year_end = $job['year_end'] ?? date('Y');

  $attempt = 0;
  $urls = [];

  while ($attempt < $retryLimit && count($urls) < $count) {
    $attempt++;

    $template = get_option('chatgpt_prompt_template', 'Find exactly {count} real article URLs about "{topic}" from {year_start} to {year_end}. Return only https:// URLs, one per line.');
    $prompt = str_replace(
      ['{topic}', '{count}', '{year_start}', '{year_end}'],
      [$topic, $count, $year_start, $year_end],
      $template
    );


    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
      'headers' => [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
      ],
      'body' => json_encode([
        'model' => 'gpt-3.5-turbo',
        'messages' => [
          ['role' => 'system', 'content' => 'You return only clean article URLs.'],
          ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
      ])
    ]);

    $body_json = wp_remote_retrieve_body($response);
    error_log("[ChatGPT Scraper] Raw response (Attempt $attempt): " . $body_json);
    error_log("[GPT Prompt]: " . $prompt);

    $body = json_decode($body_json, true);
    $text = $body['choices'][0]['message']['content'] ?? '';

    preg_match_all('/https?:\/\/[^\s)"]+/', $text, $matches);
    $new_urls = array_unique(array_map('trim', $matches[0]));

    $urls = array_unique(array_merge($urls, $new_urls));
    $urls = array_filter($urls, function ($url) {
      return !preg_match('/(404|403|forbidden|denied|not-found|error)/i', $url);
    });

    $urls = array_slice($urls, 0, $count);
  }

  // Fallback if nothing found
  // if (empty($urls)) {
  //   error_log("[GPT Scraper] GPT returned no valid URLs. Falling back to static ones.");
  //   return [
  //     'https://news.abs-cbn.com/news/2024/03/21/sample-event-post',
  //     'https://www.spot.ph/newsfeatures/112233/bike-events-in-manila-2024',
  //   ];
  // }

  return $urls;
}



// Settings
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
  register_setting('chatgpt_settings', 'chatgpt_model');
  register_setting('chatgpt_settings', 'chatgpt_prompt_template');

  add_settings_section('chatgpt_section', 'API Configuration', null, 'chatgpt-webscraper-settings');

  add_settings_field('chatgpt_api_key', 'OpenAI API Key', function () {
    $value = esc_attr(get_option('chatgpt_api_key'));
    echo "<input type='text' name='chatgpt_api_key' value='$value' style='width: 400px'>";
  }, 'chatgpt-webscraper-settings', 'chatgpt_section');

  add_settings_field('chatgpt_model', 'GPT Model', function () {
    $value = esc_attr(get_option('chatgpt_model', 'gpt-3.5-turbo'));
    echo "<input type='text' name='chatgpt_model' value='$value' style='width: 200px'>";
  }, 'chatgpt-webscraper-settings', 'chatgpt_section');

  add_settings_field('chatgpt_prompt_template', 'GPT URL Search Prompt', function () {
    $value = esc_attr(get_option('chatgpt_prompt_template', 'Find exactly {count} real article URLs about "{topic}" from {year_start} to {year_end}. Return only https:// URLs, one per line.'));
    echo "<textarea name='chatgpt_prompt_template' rows='4' style='width: 100%;'>$value</textarea>";
    echo '<p class="description">Use <code>{topic}</code>, <code>{count}</code>, <code>{year_start}</code>, <code>{year_end}</code> for dynamic values.</p>';
  }, 'chatgpt-webscraper-settings', 'chatgpt_section');
});


?>
