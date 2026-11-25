<?php
// API documentation. Approved, logged-in users only; .htaccess blocks every
// other file in docs/, so this gatekeeper is the only way in.
declare(strict_types=1);
require_once __DIR__ . '/../includes/lib.php';

$user = require_approved();
$isOrganizer = is_admin_email($user['email']);
$api = abs_url('api/v1');

$participantExample = [
    'id' => 7, 'email' => 'ralphie@example.com', 'name' => 'Ralphie Parker',
    'address' => ['line1' => '3159 Cleveland St', 'line2' => '', 'city' => 'Hohman', 'state' => 'IN', 'postal' => '46320'],
    'phone' => '219-555-0142', 'favorite_style' => 'stout', 'hated_style' => 'sour',
    'notes' => 'No pink bunny suits.', 'heard_via' => 'Leg lamp', 'is_organizer' => false, 'is_founder' => false,
    'approved' => true, 'profile_complete' => true, 'agreed_to_rules' => true,
    'agreed_at' => '2026-11-28T19:04:11Z', 'ready' => true, 'in_draw' => true,
    'shipped_at' => null, 'tracking' => '', 'created_at' => '2026-11-27T02:13:40Z',
    'last_login_at' => '2026-12-01T15:22:09Z',
];

// [method, path, scope, summary, request body|null, response|null, notes]
$sections = [
    'Basics' => [
        ['GET', '/', 'read', 'Ping. Tells you the key works and what it can do.', null,
         ['name' => 'BEERxCHG API', 'version' => 'v1', 'key' => ['name' => 'Shipping bot', 'scope' => 'read'], 'docs' => abs_url('docs/')], ''],
        ['GET', '/status', 'read', 'Settings plus head counts. Good for a dashboard or a nagging bot.', null,
         ['settings' => ['draw_when' => 'Dec 1 at 8pm', 'ship_before' => '2026-12-20', 'signups_open' => true, 'min_ounces' => 96],
          'counts' => ['participants' => 12, 'approved' => 11, 'ready' => 9, 'shipped' => 0], 'drawn' => false], ''],
        ['GET', '/rules', 'read', 'The numbered rules everyone has to check off, with dates filled in.', null,
         ['rules' => [['number' => 1, 'rule' => 'I will ship at least 96 oz of beer.', 'detail' => "That's three 32 oz crowlers. Not two crowlers and an apology."]]],
         'Trimmed to one rule here. You get all of them.'],
        ['GET', '/styles', 'read', 'Valid beer style ids for favorite_style and hated_style.', null,
         ['styles' => [['id' => 'ipa', 'label' => 'IPA (West Coast / American)'], ['id' => 'stout', 'label' => 'Stout']],
          'hated_style_extra' => ['id' => 'none', 'label' => "Nothing. They'll drink anything"]],
         'hated_style also accepts "none". favorite_style does not, because pick something.'],
    ],
    'Settings' => [
        ['GET', '/settings', 'read', 'Current draw date text, ship-by date and whether sign-ups are open.', null,
         ['draw_when' => 'To be determined', 'ship_before' => '2026-12-20', 'signups_open' => true, 'min_ounces' => 96], ''],
        ['PATCH', '/settings', 'write', 'Change any of the settings. Send only what you want changed.',
         ['draw_when' => 'Dec 1 at 8pm, after a few beers', 'ship_before' => '2026-12-20', 'signups_open' => false],
         ['draw_when' => 'Dec 1 at 8pm, after a few beers', 'ship_before' => '2026-12-20', 'signups_open' => false, 'min_ounces' => 96],
         'ship_before is YYYY-MM-DD and means "must ship before this date." Changing it rewrites rule 2 everywhere.'],
    ],
    'Participants' => [
        ['GET', '/participants', 'read', 'Everybody, with addresses, phones and tastes. Add ?ready=true or ?ready=false to filter.', null,
         ['participants' => [$participantExample]],
         '"ready" means approved + profile complete + checked every rule. Only ready people go into the draw.'],
        ['GET', '/participants/{id}', 'read', 'One participant.', null, $participantExample, ''],
        ['POST', '/participants', 'write', 'Pre-register someone by email. They still log in with a code and fill out their own profile and rules.',
         ['email' => 'flick@example.com', 'approved' => true],
         ['id' => 13, 'email' => 'flick@example.com', 'approved' => true, 'ready' => false, '…' => '…'],
         'Returns 201. Skips the challenge question, since you are the organizer and presumably know Flick. 409 if the email already exists.'],
        ['PATCH', '/participants/{id}', 'write', 'Approve someone, promote them to organizer, change their email, or fix their profile. Send only the fields you are changing.',
         ['approved' => true, 'address' => ['line2' => 'Apt 2'], 'hated_style' => 'sour'],
         $participantExample,
         'Allowed: approved, is_organizer, email, name, address.{line1,line2,city,state,postal}, phone, favorite_style, hated_style, notes. '
         . 'You cannot check the rules for someone. They have to do that themselves. Un-approving someone in the current draw is a 409. '
         . 'Promoting (is_organizer: true) also approves them. Founders (the organizers in the config file) cannot be demoted, deleted or have their email changed: 409 founder.'],
        ['DELETE', '/participants/{id}', 'write', 'Remove someone completely.', null, null,
         'Returns 204. 409 if they are in the current draw (reset the draw first) or are a founder.'],
    ],
    'The draw' => [
        ['GET', '/draw', 'read', 'Whether the draw happened and, if so, who has whom. Spoilers.', null,
         ['drawn' => true, 'drawn_at' => '2026-12-02T02:00:13Z', 'pairs' => [
             ['giver' => ['id' => 7, 'name' => 'Ralphie Parker'], 'recipient' => ['id' => 9, 'name' => 'Schwartz'], 'shipped_at' => null, 'tracking' => '']]],
         'If you are playing too, maybe don\'t point a read key at this and then look at the output.'],
        ['POST', '/draw', 'write', 'Run the draw: everyone ready goes into one random loop, sign-ups close, match emails go out.',
         ['notify' => true],
         ['drawn' => true, 'drawn_at' => '2026-12-02T02:00:13Z', 'pairs' => ['…'], 'emails_sent' => 9],
         'Returns 201. notify defaults to true; send false to skip the emails (then use POST /draw/emails later). 409 if already drawn or fewer than 2 people are ready.'],
        ['DELETE', '/draw?confirm=true', 'write', 'Reset the draw. Deletes every match and shipping status.', null, null,
         'Returns 204. Without ?confirm=true you get a 422, because this is how friendships end.'],
        ['POST', '/draw/emails', 'write', 'Re-send everyone their match email, for the people who "never got it."', null,
         ['emails_sent' => 9], '409 if there is no draw yet.'],
    ],
    'Shipping' => [
        ['POST', '/participants/{id}/shipment', 'write', 'Mark that participant\'s box as shipped. Emails their recipient.',
         ['tracking' => '1Z999AA10123456784'], $participantExample,
         '{id} is the sender. tracking is optional. 409 if they have no match.'],
        ['DELETE', '/participants/{id}/shipment', 'write', 'Un-ship. For misclicks and confessions.', null, $participantExample, ''],
    ],
];

function doc_json($v): string
{
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function doc_curl(string $api, string $method, string $path, ?array $body): string
{
    $p = str_replace('{id}', '7', $path);
    $cmd = "curl -s" . ($method !== 'GET' ? " -X $method" : '') . " \"$api$p\" \\\n  -H \"Authorization: Bearer \$BX_KEY\"";
    if ($body !== null) {
        $cmd .= " \\\n  -H \"Content-Type: application/json\" \\\n  -d '" . json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "'";
    }
    return $cmd;
}

function doc_anchor(string $method, string $path): string
{
    return strtolower($method) . '-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $path), '-');
}

page_start('API docs', $user);
?>
<div class="docs">
  <nav class="toc" aria-label="On this page">
    <p class="toc-h">On this page</p>
    <ul>
      <li><a href="#start">Getting started</a></li>
      <li><a href="#auth">Keys &amp; auth</a></li>
      <li><a href="#errors">Errors</a></li>
      <?php foreach ($sections as $title => $eps): ?>
        <li><a href="#<?= h(doc_anchor('s', $title)) ?>"><?= h($title) ?></a>
          <ul>
          <?php foreach ($eps as [$m, $p]): ?>
            <li><a href="#<?= h(doc_anchor($m, $p)) ?>"><span class="verb <?= h(strtolower($m)) ?>"><?= h($m) ?></span> <?= h($p) ?></a></li>
          <?php endforeach; ?>
          </ul>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <article class="doc-body">
    <h1 class="page-title">The BEERxCHG API</h1>
    <p class="lede">Everything the Organizer page can do, minus the clicking. JSON in, JSON out, one base URL,
       and no SDK, because it's a beer exchange, not a startup.</p>

    <section id="start">
      <h2>Getting started</h2>
      <p>Base URL:</p>
      <pre><code><?= h($api) ?></code></pre>
      <ol>
        <li><?php if ($isOrganizer): ?>Go to <a href="<?= h(url('admin.php#api-keys')) ?>">Organizer → API keys</a>
            and generate a key.<?php else: ?>Ask the organizer for a key. Only the organizer can make them,
            because handing everyone the power to re-run the draw seemed unwise.<?php endif; ?></li>
        <li>Put it somewhere safe. It's shown exactly once.</li>
        <li>Try it:</li>
      </ol>
      <pre><code>export BX_KEY="bxk_…"
<?= h(doc_curl($api, 'GET', '/status', null)) ?></code></pre>
      <p>Every request has to be HTTPS. Plain HTTP gets redirected, and your key went over the wire in the clear
         on the way. Don't do that.</p>
    </section>

    <section id="auth">
      <h2>Keys &amp; auth</h2>
      <p>Send the key as a bearer token:</p>
      <pre><code>Authorization: Bearer bxk_3f9a…</code></pre>
      <p>If something in your stack eats the <code>Authorization</code> header, <code>X-API-Key: bxk_…</code> works too.
         Cookies don't work on the API, on purpose.</p>
      <div class="tablewrap">
      <table>
        <thead><tr><th>Access</th><th>Can do</th></tr></thead>
        <tbody>
          <tr><td><span class="scope-pill">read</span></td><td><code>GET</code> anything: participants (addresses, phones and all), settings, rules, the draw.</td></tr>
          <tr><td><span class="scope-pill rw">read / write</span></td><td>All of that, plus <code>POST</code>, <code>PATCH</code> and <code>DELETE</code>: approve people, change settings, run or reset the draw, mark shipments.</td></tr>
        </tbody>
      </table>
      </div>
      <p>A read key that tries to write gets a <code>403</code>. A revoked or made-up key gets a <code>401</code>.
         Keys don't expire. The organizer revokes them when they leak, or when the bot that uses them gets weird.</p>
      <p><strong>Read keys are not harmless.</strong> They can see everybody's home address and the full pairing
         list. Treat every key like a password.</p>
    </section>

    <section id="errors">
      <h2>Errors</h2>
      <p>Anything that isn't a <code>2xx</code> comes back like this:</p>
      <pre><code><?= h(doc_json(['error' => ['code' => 'validation_failed', 'message' => 'Some fields are invalid.',
          'fields' => ['address.postal' => "That ZIP doesn't look real."]]])) ?></code></pre>
      <div class="tablewrap">
      <table>
        <thead><tr><th>Status</th><th>Means</th></tr></thead>
        <tbody>
          <tr><td><code>400</code></td><td>The body isn't a JSON object.</td></tr>
          <tr><td><code>401</code></td><td>No key, a bad key, or a revoked key.</td></tr>
          <tr><td><code>403</code></td><td>A read key tried to write.</td></tr>
          <tr><td><code>404</code></td><td>No such endpoint or participant.</td></tr>
          <tr><td><code>405</code></td><td>Wrong method. The <code>Allow</code> header lists the right ones.</td></tr>
          <tr><td><code>409</code></td><td>The request conflicts with reality: already drawn, already signed up, in the draw, and so on.</td></tr>
          <tr><td><code>413</code></td><td>Body over 64 KB. It's a beer exchange.</td></tr>
          <tr><td><code>422</code></td><td>Validation failed. <code>fields</code> says which ones.</td></tr>
        </tbody>
      </table>
      </div>
      <p>Timestamps are ISO 8601 in UTC. <code>ship_before</code> is a plain <code>YYYY-MM-DD</code> date.</p>
    </section>

    <?php foreach ($sections as $title => $eps): ?>
    <section id="<?= h(doc_anchor('s', $title)) ?>">
      <h2><?= h($title) ?></h2>
      <?php foreach ($eps as [$m, $p, $scope, $summary, $body, $resp, $notes]): ?>
        <div class="endpoint" id="<?= h(doc_anchor($m, $p)) ?>">
          <h3 class="ep-title"><span class="verb <?= h(strtolower($m)) ?>"><?= h($m) ?></span>
            <code><?= h($p) ?></code>
            <span class="scope-pill<?= $scope === 'write' ? ' rw' : '' ?>"><?= $scope === 'write' ? 'read / write' : 'read' ?></span></h3>
          <p><?= h($summary) ?></p>
          <?php if ($notes !== ''): ?><p class="hint"><?= h($notes) ?></p><?php endif; ?>
          <div class="ep-grid">
            <div>
              <p class="code-label">Request</p>
              <pre><code><?= h(doc_curl($api, $m, $p, $body)) ?></code></pre>
            </div>
            <?php if ($resp !== null): ?>
            <div>
              <p class="code-label">Response</p>
              <pre><code><?= h(doc_json($resp)) ?></code></pre>
            </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>

    <section>
      <h2>Fine print</h2>
      <ul>
        <li>No rate limits yet. Don't make us add them.</li>
        <li>Everything the API does sends the same emails the website would: match emails on a draw, "it's on the way" on a shipment.</li>
        <li>The API can't check rules on anyone's behalf, and it can't see or make login codes.
            Those stay between a person and their inbox.</li>
        <li>v1 won't change under you. If it has to, there'll be a v2 and a sarcastic changelog.</li>
      </ul>
    </section>
  </article>
</div>
<?php page_end();
