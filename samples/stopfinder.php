<?php
require('../511.php');
$query = empty($_GET['q']) ? null : $_GET['q'];
?><!DOCTYPE html>
<html>
<head>
  <title>Stop Finder Sample</title>
</head>
<body>
  <form>
    <label>
      Query:
      <input type="text" name="q" value="<?php echo htmlspecialchars($query) ?>" />
    </label>
    <button type="submit">Search</button>
  </form>
<?php
if (!empty($query)) {
  $planner = new JourneyPlanner();
  $results = $planner->stopFinder($query);
  echo '<pre>', json_encode($results, JSON_PRETTY_PRINT), '</pre>';
}
?>
</body>
</html>