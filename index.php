<?php
$categoryUrl = 'https://indico.cern.ch/export/categ/5666.json?from=today&order=start';
$response = json_decode(file_get_contents($categoryUrl), true);

// Additional specific Indico event IDs to include (fetched individually)
$additionalEventIds = json_decode(file_get_contents(/eos/project/f/fccsw-web/www/fccsw-meetings/extra_events.json'), true) ?? [];

$meetings = [
  'today'      => [],
  'this-week'  => [],
  'next-week'  => [],
  'this-month' => [],
  'next-month' => [],
  'later'      => [],
];
$laterNextMonth = false;
$titleCount = [];
$seenIds = [];

function parseMeetingResult($result) {
  $startTime = new DateTime(
    $result['startDate']['date'] . ' ' . $result['startDate']['time'],
    new DateTimeZone($result['startDate']['tz'])
  );
  $meeting = [
    'id'             => $result['id'],
    'title'          => $result['title'],
    'description'    => $result['description'],
    'startTime'      => $startTime->format('Y M d, H:i T'),
    'startTimestamp' => $startTime->getTimestamp(),
    'url'            => $result['url'],
    'location'       => empty($result['roomFullname']) ? $result['location'] : $result['roomFullname'],
  ];
  return [$meeting, $startTime];
}

function bucketForMeeting($startTime, &$laterNextMonth) {
  if (date('%Y-%W-%d') == $startTime->format('%Y-%W-%d')) {
    return 'today';
  } elseif (date('W') == $startTime->format('W')) {
    if ((date('m') + 1) == $startTime->format('m')) $laterNextMonth = true;
    return 'this-week';
  } elseif ((date('W') + 1) == $startTime->format('W')) {
    if ((date('m') + 1) == $startTime->format('m')) $laterNextMonth = true;
    return 'next-week';
  } elseif (date('m') == $startTime->format('m')) {
    return 'this-month';
  } elseif ((date('m') + 1) == $startTime->format('m')) {
    return 'next-month';
  }
  return 'later';
}

function tryAddMeeting($result, &$meetings, &$titleCount, &$seenIds, &$laterNextMonth) {
  [$meeting, $startTime] = parseMeetingResult($result);

  if (in_array($meeting['id'], $seenIds)) return;
  $seenIds[] = $meeting['id'];

  $title = $meeting['title'];
  $titleCount[$title] = ($titleCount[$title] ?? 0) + 1;
  if ($titleCount[$title] > 1) return;

  $bucket = bucketForMeeting($startTime, $laterNextMonth);
  if ($bucket !== null) {
    $meetings[$bucket][] = $meeting;
  }
}

// Process category feed
foreach ($response['results'] as $result) {
  tryAddMeeting($result, $meetings, $titleCount, $seenIds, $laterNextMonth);
}

// Process additional events by ID (today or future only)
$today = new DateTime('today');
foreach ($additionalEventIds as $eventId) {
  $eventUrl = 'https://indico.cern.ch/export/event/' . intval($eventId) . '.json';
  $data = @file_get_contents($eventUrl);
  if (!$data) continue;
  $eventResponse = json_decode($data, true);
  if (empty($eventResponse['results'])) continue;
  $result = $eventResponse['results'][0];
  $startTime = new DateTime(
    $result['startDate']['date'] . ' ' . $result['startDate']['time'],
    new DateTimeZone($result['startDate']['tz'])
  );
  if ($startTime < $today) continue;
  tryAddMeeting($result, $meetings, $titleCount, $seenIds, $laterNextMonth);
}

// Sort each bucket by start time
foreach ($meetings as &$bucket) {
  usort($bucket, fn($a, $b) => $a['startTimestamp'] <=> $b['startTimestamp']);
}
unset($bucket);
?>

<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FCC Software Meetings</title>
    <link href="./bootstrap/bootstrap-5.3.3/css/bootstrap.min.css"
          rel="stylesheet">
  </head>
  <script>
  if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches && document.getElementById("FollowDarkMode") ) {
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        console.log('switching to dark theme');
    }

    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
        document.documentElement.setAttribute('data-bs-theme', 'light');
        console.log('switching to light theme');
    }
  </script>
  <body>
    <div class="container">
      <?php foreach($meetings as $period => $meetingsInPeriod): ?>
      <?php if ($period == 'today' && count($meetingsInPeriod) > 0): ?>
      <h4 class="mt-3 mb-0">Today</h4>
      <?php endif ?>

      <?php if ($period == 'this-week' && count($meetingsInPeriod) > 0): ?>
      <?php if (count($meetings['today']) > 0): ?>
      <h4 class="mt-3 mb-0">Later This Week</h4>
      <?php else: ?>
      <h4 class="mt-3 mb-0">This Week</h4>
      <?php endif ?>
      <?php endif ?>

      <?php if ($period == 'next-week' && count($meetingsInPeriod) > 0): ?>
      <h4 class="mt-3 mb-0">Next Week</h4>
      <?php endif ?>

      <?php if ($period == 'this-month' && count($meetingsInPeriod) > 0): ?>
      <h4 class="mt-3 mb-0">Later This Month</h4>
      <?php endif ?>

      <?php if ($period == 'next-month' && count($meetingsInPeriod) > 0): ?>
      <?php if ($laterNextMonth): ?>
      <h4 class="mt-3 mb-0">Later Next Month</h4>
      <?php else: ?>
      <h4 class="mt-3 mb-0">Next Month</h4>
      <?php endif ?>
      <?php endif ?>

      <?php if ($period == 'later' && count($meetingsInPeriod) > 0): ?>
      <h4 class="mt-3 mb-0">Upcoming</h4>
      <?php endif ?>

      <?php foreach($meetingsInPeriod as $meeting): ?>
      <div class="row me-1">
        <div class="col mt-3 bg-light rounded">
          <p class="text-muted mb-0">
            <small><?= $meeting['startTime']; ?></small>
          </p>
          <p class="h3">
            <a href="<?= $meeting['url']; ?>"
               class="link-underline link-underline-opacity-0 link-underline-opacity-50-hover"
               title="<?= $meeting['startTime'] . " | " . $meeting['title']; ?>"
               target="_blank"><?= $meeting['title']; ?></a>
          </p>
          <div class="row text-muted mb-2">
            <div class="col">
              <small>
                <svg xmlns="http://www.w3.org/2000/svg"
                     width="16"
                     height="16"
                     fill="currentColor"
                     class="bi bi-geo-alt"
                     viewBox="0 0 16 16">
                  <path d="M12.166 8.94c-.524 1.062-1.234 2.12-1.96 3.07A32 32
                           0 0 1 8 14.58a32 32 0 0
                           1-2.206-2.57c-.726-.95-1.436-2.008-1.96-3.07C3.304
                           7.867 3 6.862 3 6a5 5 0 0 1 10 0c0 .862-.305
                           1.867-.834 2.94M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0
                           4.314 6 10 6 10"/>
                  <path d="M8 8a2 2 0 1 1 0-4 2 2 0 0 1 0 4m0 1a3 3 0 1 0 0-6 3
                           3 0 0 0 0 6"/>
                </svg>
                <?= $meeting['location']; ?>
              </small>
            </div>
            <div class="col text-end">
              <small>
                <?php if (!empty($meeting['description'])): ?>
                <a class="link-secondary link-underline link-underline-opacity-0"
                   data-bs-toggle="collapse"
                   href="#event-desc-<?= $meeting['id']; ?>">Show&nbsp;description</a>
                <?php endif ?>
              </small>
            </div>
          </div>
          <div class="collapse mb-3" id="event-desc-<?= $meeting['id']; ?>">
            <div class="card card-body">
              <?= $meeting['description']; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach ?>
      <?php endforeach ?>

      <?php if(array_sum(array_map('count', $meetings)) < 1): ?>
      <div class="row me-1">
        <div class="col mt-3 bg-light rounded">
          <p class="text-muted mt-2 mb-2">
            No upcoming meetings planned yet.
          </p>
        </div>
      </div>
      <?php endif ?>

      <p class="mt-3">
        The Indico category for all FCC Software and Computing meetings can be found
        <a href="https://indico.cern.ch/category/5666/"
           class="link-underline link-underline-opacity-0 link-underline-opacity-50-hover"
           target="_blank">here</a>.
      <p>
    </div>

    <script src="./bootstrap/bootstrap-5.3.3/js/bootstrap.bundle.min.js"></script>
  </body>
</html>
