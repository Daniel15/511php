<?php
require('../511.php');
?><!DOCTYPE html>
<html>
<head>
  <title>Journey Planner Sample</title>
</head>
<body>
<?php
if (empty($_GET)) {
?>
  <form>
    <label>
      Origin ID (use <a href="stopfinder.php">Stopfinder API</a> to find this):
      <input type="text" name="origin_id" size="50" />
    </label><br />
    <label>
      Destination ID:
      <input type="text" name="destination_id" size="50" />
    </label><br />
    <label>
      Date/Time:
      <input type="text" name="time" value="today 1:00 PM" />
    </label><br />
    <button type="submit">Search</button>
  </form>
<?php
} else {
  $planner = new JourneyPlanner();
  // TODO: Cleanse arguments.
  $args = $_GET;
  $args['time'] = strtotime($_GET['time']);
  $results = $planner->plan($args);
  echo '<pre>', json_encode($results, JSON_PRETTY_PRINT), '</pre>';
}
?>
</body>
</html>