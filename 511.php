<?php
class JourneyPlanner {
  const BASE_URL = 'http://tripplanner.transit.511.org/ultralite/';
  const STOPFINDER_URI = 'XML_STOPFINDER_REQUEST';
  const TRIP_URI = 'XML_TRIP_REQUEST2';

  /**
   * Perform a stopfinder request for the specified query
   *
   * @param {String} Search query
   * @return {Array} Stop finder results
   */
  public function stopFinder($query) {
    $params = array(
      'name_sf' => $query,

      // Boilerplate junk
      'coordOutputFormat' => 'WGS84',
      'locationServerActive' => 1,
      'stateless' => 1,
      'type_sf' => 'any'
    );

    $data = $this->doRequest(self::STOPFINDER_URI, $params);
    $results = array();
    foreach ($data->sf->p as $point) {
      $results[] = array(
        'id' => (string)$point->r->stateless,
        'name' => (string)$point->n,
        'subname' => (string)$point->r->pc,
        'type' => (string)$point->ty,
        'pos' => $this->parseCoordinates($point->r->c),
      );
    }

    return $results;
  }

  /**
   * Plan a journey from an origin to a destination
   *
   * @param {Array} $options Search options (TODO: Document these)
   * @return {Array} The possible journey plans
   */
  public function plan($options) {
    $params = array(
      'sessionID' => 0,
      'requestID' => 0,
      'calcNumberOfTrips' => 4,
      'coordListOutputFormat' => 'STRING',
      'coordOutputFormat' => 'WGS84',
      'coordOutputFormatTail' => 0,
      'useRealtime' => 1,
      'locationServerActive' => 1,
      'calcOneDirection' => 1,
      'itOptionsActive' => 1,
      'ptOptionsActive' => 1,
      'imparedOptionsActive' => 1,
      'excludedMeans' => 'checkbox',
      'useProxFootSearch' => 1
    );

    $this->setPointParams($params, $options, 'origin');
    $this->setPointParams($params, $options, 'destination');

    if (!empty($options['time'])) {
      $params['itdTime'] = date('Hi', $options['time']);
      $params['itdDate'] = date('Ymd', $options['time']);
    }
    if (!empty($options['is_arrival_time'])) {
      $params['itdTripDateTimeDepArr'] = 'arr'; // Pirate mode activated
    }

    $data = $this->doRequest(self::TRIP_URI, $params);

    $results = array(
      'routes' => array()
    );

    if (!empty($data->ts) && !empty($data->ts->tp)) {
      foreach ($data->ts->tp as $route_data) {
        $results['routes'][] = $this->parseRoute($route_data);
      }
    }
    return $results;
  }

  /**
   * Set origin or destination point parameters in the request params.
   * 
   * @param {Array}  $params  Request querystring parameters the point should be
   *                          added to
   * @param {Array}  $options Options for the request
   * @param {String} $type    Point type ("origin" or "destination")
   */

  private function setPointParams(&$params, $options, $type) {
    if (!empty($options[$type . '_id'])) {
      $params['name_' . $type] = $options[$type . '_id'];
      $params['type_' . $type] = 'any';
    } elseif (!empty($options[$type . '_lat']) && !empty($options[$type . '_lon'])) {
      $params['name_' . $type] = $this->formatCoordinates($options[$type . '_lat'], $options[$type . '_lon']);
      $params['type_' . $type] = 'coord';
    } else {
      throw new Exception('Please specify ' . $type . ' either as ID or lat/lon');
    }
  }

  /**
   * Parse a route from the ugly XML format into a user-friendly format
   *
   * @param {SimpleXMLElement} $route_data Raw route data
   * @return {Array} Nicely formatted route
   */
  private function parseRoute(SimpleXMLElement $route_data) {
    $duration_pieces = explode(':', $route_data->d);
    $duration = (60 * (int)$duration_pieces[0]) + (int)$duration_pieces[1];
    $route = array(
      'duration' => $duration,
      'legs' => array()
    );
    foreach ($route_data->ls->l as $leg_data) {
      $route['legs'][] = $this->parseLeg($leg_data);
    }

    // Because we're kind souls who like having a nice API.
    $last_leg = count($route['legs']) - 1;
    $route['depart_time'] = $route['legs'][0]['from']['time'];
    $route['arrive_time'] = $route['legs'][$last_leg]['to']['time'];

    return $route;
  }

  /**
   * Parse a leg from a route route from the ugly XML format into a 
   * user-friendly format
   *
   * @param {SimpleXMLElement} $leg_data Raw leg data
   * @return {Array} Nicely formatted leg
   */
  private function parseLeg(SimpleXMLElement $leg_data) {
    // This sanity check is the only sane thing here
    if (count($leg_data->ps->p) != 2) {
      throw new Exception('Route leg where without two points. Something done goofed');
    }

    $from = $leg_data->ps->p[0];
    $to = $leg_data->ps->p[1];

    $mode = (string)$leg_data->m->de;
    if ($mode === 'Fussweg') {
      // Transportation mode, now with 100% less German
      $mode = 'Walk';
      $type = 'walk';
    // All these transportation providers don't have a definitive flag in the API
    // so we need to resort to partial string matching...
    } elseif (strpos($mode, 'SamTrans') !== false) {
      $type = 'samtrans';
    } elseif (strpos($mode, 'VTA') !== false) {
      $type = 'vta';
    } elseif (strpos($mode, 'Muni') !== false) {
      $type = 'muni';
    } elseif (strpos($mode, 'Caltrain') !== false) {
      $type = 'caltrain';
    } elseif (strpos($mode, 'BART') !== false) {
      $type = 'bart';
    } elseif (strpos($mode, 'Dumbarton') !== false) {
      $type = 'dumbarton';
    } elseif (strpos($mode, 'Stanford Marguerite') !== false) {
      $type = 'stanford';
    } elseif (strpos($mode, 'AC Transit') !== false) {
      $type = 'actransit';
    } else {
      $type = 'unknown';
    }

    $leg = array(
      'mode' => array(
        'type' => $type,
        'name' => $mode,
        'dest' => (string)$leg_data->m->des,
      ),
      'from' => $this->parseLegPoint($leg_data->ps->p[0]),
      'to' => $this->parseLegPoint($leg_data->ps->p[1]),
    );

    // Any passed stops?
    if (!empty($leg_data->pss)) {
      $leg['passed_stops'] = array();
      foreach ($leg_data->pss->s as $passed_stop_data) {
        $passed_stop = explode(';', $passed_stop_data);
        $id = $passed_stop[0];
        $name = $passed_stop[1];

        // Skip the last one
        if ($name === $leg['to']['name'])
          continue;

        $date = $this->parseAPIDateTime($passed_stop[2], $passed_stop[3]);
        // Skip if the date is invalid - The first stop has "0000-1" or "00000"
        // as the date.
        if ($date == null) {
          continue;
        }

        $leg['passed_stops'][] = array(
          'id' => $id,
          'name' => $name,
          'timestamp' => $date->getTimestamp(),
          'time' => $date->format('H:i'),
        );
      }
    }

    return $leg;
  }

  /**
   * Parse an individual leg point (either origin or destination) from the 
   * ugly XML format into a user-friendly format
   *
   * @param {SimpleXMLElement} $point_data Raw point data
   * @return {Array} Nicely formatted point
   */
  private function parseLegPoint(SimpleXMLElement $point_data) {
    $date = $this->parseAPIDateTime($point_data->st->da, $point_data->st->t);
    return array(
      'id' => (string)$point_data->r->id,
      'name' => (string)$point_data->n,
      'timestamp' => $date->getTimestamp(),
      'time' => $date->format('H:i'),
      'pos' => $this->parseCoordinates($point_data->r->c)
    );
  }

  /**
   * Parse a 511.org API date (in the format "20131122 1938") into a PHP DateTime
   * object.
   *
   * @param {String} $date
   * @param {String} $time
   * @return {DateTime} Parsed date/time value
   */
  private function parseAPIDateTime($date, $time) {
    return DateTime::createFromFormat(
      // In the format "20131122 1938"
      'Ymd Hi', 
      $date . ' ' . $time
    );
  }

  /**
   * Format a lat/lon pair into coordinate format recognised by the API
   *
   * @param {Double} $lat Latitude
   * @param {Double} $lon Longitude
   * @return {String} Lat/lon pair for API request
   */
  private function formatCoordinates($lat, $lon) {
    return $lon . ':' . $lat . ':WGS84[DD.ddddd]';
  }

  /** 
   * Parse a comma separated coordinate pair into lat/lon values
   * 
   * @param {String} $raw Coordinates
   * @return {Array} Latitude and Longitude
   */
  private function parseCoordinates($raw) {
    $split = explode(',', $raw);
    return array(
      'lon' => ((float)$split[0] / 1000000),
      'lat' => ((float)$split[1] / 1000000),
    );
  }

  /**
   * Actually perform a request to the 511.org Transit Planner API. 
   *
   * @param {String} $uri    Relative URI to send request to
   * @param {String} $params Array of key => value pairs to send in querystring
   * @return {SimpleXMLElement} XML data returned from the API
   */
  private function doRequest($uri, $params) {
    $query = http_build_query($params, null, '&');
    $full_url = self::BASE_URL . $uri . '?' . $query;
    $stream = stream_context_create(array(
      'http' => array(
          'header' => 'User-Agent: 511PHP/1.0 (https://dl.vc/511)'
      )
    ));

    $data = file_get_contents($full_url, false, $stream);
    return simplexml_load_string($data);
  }
}