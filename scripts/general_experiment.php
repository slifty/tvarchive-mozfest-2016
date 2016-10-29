<?php
  
  ////////////////////////////////////////
  // You can ignore everything below this
  ////////////////////////////////////////
  date_default_timezone_set("UTC");
  global $dt5k_url, $program_length_limit, $project_id;
  $project_id = 172;
  $dt5k_url = "http://tv-research4.us.archive.org/api/";
  $program_length_limit = 14400; // in seconds (4 hours)


  // Make sure an experiment file was passed in
  if(sizeof($argv) < 2) {
      echo("USAGE: php general_experiment.php http://url.to/your.mp3".PHP_EOL);
      die();
  }
  $mp3_url = $argv[1];

  // HELPER DT5K METHODS
  function get_media($media_id) {
    global $dt5k_url;
    $curl_url = $dt5k_url."media/".$media_id;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $curl_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $media_output = curl_exec ($ch);
    curl_close ($ch);
    $media = json_decode($media_output);
    return $media;
  }
  function get_task($task_id, $return_object=true) {
    global $dt5k_url;
    $curl_url = $dt5k_url."media_tasks/".$task_id;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $curl_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $task_output = curl_exec ($ch);
    curl_close ($ch);
    $task = json_decode($task_output);
    if($return_object)
      return $task;
    return $task_output;
  }

  function resolve_tasks($tasks) {
    global $dt5k_url;

    echo("\n\rWaiting for tasks to resolve");
    while(sizeof($tasks) > 0) {
      echo(".");
      sleep(5);
      foreach($tasks as $key => $task) {
        $curl_url = $dt5k_url."media_tasks/".$task->id;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $curl_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec ($ch);
        curl_close ($ch);
        $task = json_decode($server_output);

        if($task->status->code == 3) {
          echo("\n\rFINISHED processing task: ".$task->id."\n\r");
          unset($tasks[$key]);
        }
        if($task->status->code == -1) {
          echo("\n\rERROR processing task: ".$task->id."\n\r");
          unset($tasks[$key]);
        }
      }
    }
    return;
  }

  function compare_program($program_mp3, $project_id) {
    global $dt5k_url;

    if($program_mp3 == "")
      continue;

    // Create the media
    $curl_url = $dt5k_url."media";
    $post_data = array(
      "project_id" => $project_id,
      "media_path" => $program_mp3
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $curl_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $server_output = curl_exec ($ch);
    curl_close ($ch);
    $media = json_decode($server_output);

    $media_id = $media->id;
    echo("\n\rCreated media item: ".$media->id);

    // Run the match job
    $post_data = array(
      "media_id" => $media->id,
      "type" => "full_match"
    );

    $curl_url = $dt5k_url."media_tasks";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $curl_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $server_output = curl_exec ($ch);
    curl_close ($ch);
    $task = json_decode($server_output);
    echo("\n\rCreated 'match' task: ".$task->id);

    return $task;
  }

  function load_results($task_id) {
    global $dt5k_url;

    // Get the task
    $task_output = get_task($task_id, false);
    $task = json_decode($task_output);

    echo("\n\rProccessing task: ".$task->id);

    // Get the media
    $media = get_media($task->media_id);

    if($task->status->code == -1)
      echo("TASK FAILED: ".$task->id. " ad ID - ".$media->external_id);

    if($task->status->code != 3)
      return null;

    $external_id = $media->external_id;
    $result_file = "results/json/".$media->project_id."_".$external_id.".json";
    file_put_contents($result_file, json_encode($task));
    return $task;
  }

  function glue_results($result_sets, $glue_width = 10, $channel_array = array()) {
    $glued_results = array();

    // Iterate over the result sets
    foreach($result_sets as $result_set) {
      $media = get_media($result_set->media_id);
      $program_id = $media->external_id;

      // Load the full list of matches
      $matches = $result_set->result->data->matches->corpus;
      $clip_cache = array();
      foreach($matches as $match) {
        if(!array_key_exists($match->destination_media->external_id, $clip_cache))
          $clip_cache[$match->destination_media->external_id] = array();

        // Skip matches that are too long
        // TODO: figure out a better way to detect false positives
        if($match->duration > 120)
          continue;

        $keep = true;
        if(sizeof($channel_array) > 0) {
          // Skip matches that don't fall in a valid window

          // What hour and date did the program start
          $id_parts = explode("_", $match->destination_media->external_id);
          $channel_code = $id_parts[0];
          $program_start_date = $id_parts[1];
          $program_start_hour = (int)substr($id_parts[2], 0, 2);
          $program_start_minutes = (int)substr($id_parts[2], 2, 2) + $program_start_hour * 60;

          $match_start_date = (int)($program_start_date);
          $match_start_minutes = $program_start_minutes + ceil($match->target_start / 60);
          $match_end_date = (int)($program_start_date);
          $match_end_minutes = $program_start_minutes + ceil($match->target_start / 60);

          // Was there a rollover
          if($match_start_minutes > 1440) {
            $match_start_date++;
            $match_start_minutes -= 1440;
          }
          if($match_end_minutes > 1440) {
            $match_end_date++;
            $match_end_minutes -= 1440;
          }

          // Does this match fall in the range we care about
          $keep = false;
          $start_override = -1;
          $end_override = -1;
          foreach($channel_array as $channel) {

            // Skip the rules for other channels
            if($channel_code != $channel->code)
              continue;

            // Skip the rules for other dates
            if($channel->date != $match_start_date
            && $channel->date != $match_end_date)
              continue;

            // This should never be true based on previous rules, but including defensivey
            if($channel->date > $match_end_date)
              continue;

            // This should never be true based on previous rules, but including defensivey
            if($channel->date < $match_start_date)
              continue;

            if($channel->date == $match_start_date
            && $channel->end < $match_start_minutes)
              continue;

            if($channel->date == $match_end_date
            && $channel->start > $match_end_minutes)
              continue;

            if($channel->date == $match_end_date)
              $end_override = $channel->end * 60;

            if($channel->date == $match_start_date)
              $end_override = $channel->start * 60;

            $keep = true;
          }
        }
        if($keep) {
          $clip_cache[$match->destination_media->external_id][] = $match;
        }
      }

      // Glue any clips that overlap
      foreach($clip_cache as $destination_results) {

        // First, sort the clips for this destination
        usort($destination_results, function($a, $b) {
          if ($a->start == $b->start) {
                return 0;
            }
            return ($a->start < $b->start) ? -1 : 1;
        });

        // Loop through each clip and see if it overlaps
        $clip_list = $destination_results;
        while(sizeof($clip_list) > 0) {
          $current_clip = array_shift($clip_list);
          $other_clips = array();
          foreach($clip_list as $next_clip) {
            // Is the next clip within the "glue" of the current clip's ending
            // If not, consider it next time (separately)
            if($glue_width == -1
            || $next_clip->start > $current_clip->start + $current_clip->duration + $glue_width
            || $next_clip->target_start > $current_clip->target_start + $current_clip->duration + $glue_width) {
              $other_clips[] = $next_clip;
              continue;
            }

            // Glue the clips together
            $current_clip->duration = ($next_clip->start + $next_clip->duration) - $current_clip->start;
            $current_clip->consecutive_hashes += $next_clip->consecutive_hashes;
          }

          // Add the target program ID to the clip
          $current_clip->target_media_id = $program_id;

          // Tack on the final clip
          $glued_results[] = $current_clip;

          // Now consider the ones we skipped;
          $clip_list = $other_clips;
        }
      }
    }
    return $glued_results;
  }

  function get_parts_from_media_id($media_id) {
    $parts = explode("_", $media_id);
    $channel = array_key_exists(0, $parts)?$parts[0]:"";
    $start_date = array_key_exists(1, $parts)?$parts[1]:"";
    if(strlen($start_date) == 8)
      $start_date = substr($start_date, 4, 2)."/".substr($start_date, 6, 2)."/".substr($start_date, 0, 4);

    $start_time = array_key_exists(2, $parts)?$parts[2]:"";
    if(strlen($start_time) == 6)
      $start_time = substr($start_time, 0, 2).":".substr($start_time, 2, 2).":".substr($start_time, 4, 2);

    $program = implode("_", array_slice($parts, 3));

    $parsed_media = array(
      "channel" => $channel,
      "start_date" => $start_date,
      "start_time" => $start_time,
      "program" => $program
    );

    return $parsed_media;
  }

  function get_channel_metadata($channel_code) {
    switch($channel_code) {
      case "WMUR":
        return array(
          "location" => "Manchester",
          "channel" => "WMUR",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WBZ":
        return array(
          "location" => "Boston",
          "channel" => "WBZ",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WCVB":
        return array(
          "location" => "Boston",
          "channel" => "WCVB",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WFXT":
        return array(
          "location" => "Boston",
          "channel" => "WFXT",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WHDH":
        return array(
          "location" => "Boston",
          "channel" => "WHDH",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "KNXV":
        return array(
          "location" => "Phoenix",
          "channel" => "KNXV",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KPHO":
        return array(
          "location" => "Phoenix",
          "channel" => "KPHO",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KPNX":
        return array(
          "location" => "Phoenix",
          "channel" => "KPNX",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KSAZ":
        return array(
          "location" => "Phoenix",
          "channel" => "KSAZ",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "WDJT":
        return array(
          "location" => "Milwaukee",
          "channel" => "WDJT",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "CST"
        );
      case "WISN":
        return array(
          "location" => "Milwaukee",
          "channel" => "WISN",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "CST"
        );
      case "WITI":
        return array(
          "location" => "Milwaukee",
          "channel" => "WITI",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "CST"
        );
      case "WTMJ":
        return array(
          "location" => "Milwaukee",
          "channel" => "WTMJ",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "CST"
        );
      case "KLAS":
        return array(
          "location" => "Las Vegas",
          "channel" => "KLAS",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KSNV":
        return array(
          "location" => "Las Vegas",
          "channel" => "KSNV",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KTNV":
        return array(
          "location" => "Las Vegas",
          "channel" => "KTNV",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KVVU":
        return array(
          "location" => "Las Vegas",
          "channel" => "KVVU",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KCNC":
        return array(
          "location" => "Denver",
          "channel" => "KCNC",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "MST"
        );
      case "KDVR":
        return array(
          "location" => "Denver",
          "channel" => "KDVR",
          "network" => "Fox",
          "scope" => "local",
          "time_zone" => "MST"
        );
      case "KMGH":
        return array(
          "location" => "Denver",
          "channel" => "KMGH",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "MST"
        );
      case "KUSA":
        return array(
          "location" => "Denver",
          "channel" => "KUSA",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "MST"
        );
      case "WEWS":
        return array(
          "location" => "Cleveland",
          "channel" => "WEWS",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WJW":
        return array(
          "location" => "Cleveland",
          "channel" => "WJW",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WKYC":
        return array(
          "location" => "Cleveland",
          "channel" => "WKYC",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WOIO":
        return array(
          "location" => "Cleveland",
          "channel" => "WOIO",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WFLA":
        return array(
          "location" => "Tampa",
          "channel" => "WFLA",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WFTS":
        return array(
          "location" => "Tampa",
          "channel" => "WFTS",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WTOG":
        return array(
          "location" => "Tampa",
          "channel" => "WTOG",
          "network" => "CW",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WTVT":
        return array(
          "location" => "Tampa",
          "channel" => "WTVT",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WLFL":
        return array(
          "location" => "RDF/NC",
          "channel" => "WLFL",
          "network" => "CW",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WNCN":
        return array(
          "location" => "RDF/NC",
          "channel" => "WNCN",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WRAL":
        return array(
          "location" => "RDF/NC",
          "channel" => "WRAL",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WRAZ":
        return array(
          "location" => "RDF/NC",
          "channel" => "WRAZ",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "KCRG":
        return array(
          "location" => "Cedar Rapids",
          "channel" => "KCRG",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "CST"
        );
      case "KGAN":
        return array(
          "location" => "Cedar Rapids",
          "channel" => "KGAN",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "CST"
        );
      case "KFXA":
        return array(
          "location" => "Cedar Rapids",
          "channel" => "KFXA",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "CST"
        );
      case "KYW":
        return array(
          "location" => "Philadelphia",
          "channel" => "KYW",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WCAU":
        return array(
          "location" => "Philadelphia",
          "channel" => "WCAU",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WPVI":
        return array(
          "location" => "Philadelphia",
          "channel" => "WPVI",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WTXF":
        return array(
          "location" => "Philadelphia",
          "channel" => "WTXF",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WUVP":
        return array(
          "location" => "Philadelphia",
          "channel" => "WUVP",
          "network" => "Univision",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WJLA":
        return array(
          "location" => "DC/VA/MD",
          "channel" => "WJLA",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WTTG":
        return array(
          "location" => "DC/VA/MD",
          "channel" => "WTTG",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WRC":
        return array(
          "location" => "DC/VA/MD",
          "channel" => "WRC",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "WUSA":
        return array(
          "location" => "DC/VA/MD",
          "channel" => "WUSA",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "EST"
        );
      case "CNNW":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "CNNW",
          "network" => "CNN",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      case "FOXNEWSW":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "FOXNEWSW",
          "network" => "FOX News",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      case "FBC":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "FBC",
          "network" => "FOX Business",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      case "MSNBCW":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "MSNBCW",
          "network" => "MSNBC",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      case "CSPAN":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "CSPAN",
          "network" => "CSPAN I",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      case "CSPAN2":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "CSPAN2",
          "network" => "CSPAN II",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      case "CNBC":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "CNBC",
          "network" => "CNBC",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      case "KGO":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "KGO",
          "network" => "ABC",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KNTV":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "KNTV",
          "network" => "NBC",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KPIX":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "KPIX",
          "network" => "CBS",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KQED":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "KQED",
          "network" => "PBS",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "KTVU":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "KTVU",
          "network" => "FOX",
          "scope" => "local",
          "time_zone" => "PST"
        );
      case "BLOOMBERG":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "BLOOMBERG",
          "network" => "Bloomberg",
          "scope" => "cable",
          "time_zone" => "EST"
        );
      case "BETW":
        return array(
          "location" => "SF/Oakland/SJ",
          "channel" => "BETW",
          "network" => "BET",
          "scope" => "cable",
          "time_zone" => "PST"
        );
      default:
        echo("\n\rUnknown Code: ".$channel_code);
        return array(
          "location"=> "",
          "channel" => $channel_code,
          "network" => "",
          "scope"   => "",
          "time_zone" => "UTC"
        );
    }
  }

  function generate_raw_csv($glued_results, $file_base) {
    // Define the columns of the CSV
    $file_path = $file_base."_raw.csv";
    $columns = array(
      "primary_media",
      "coverage_media",
      "channel",
      "network",
      "location",
      "channel_type",
      "program",
      "coverage_time_utc",
      "primary_start_second",
      "coverage_start_second",
      "duration",
      "match_url",
      "embed_url"
    );
    $csv_file = fopen($file_path, "w");
    fputcsv($csv_file, $columns);

    // Prepare the results
    foreach($glued_results as $match) {

      // Get media metadata
      $parsed_destination_media = get_parts_from_media_id($match->destination_media->external_id);
      $channel_metadata = get_channel_metadata($parsed_destination_media['channel']);
      $air_time = strtotime("+".floor($match->target_start / 60)." minutes", strtotime($parsed_destination_media['start_date']." ".$parsed_destination_media['start_time']));
      $air_time = strtotime("+".floor($match->target_start % 60)." seconds", $air_time);
      $air_time = date("m/d/Y H:i:s", $air_time);

      // Calculate embed buffer
      // Embeds shorter than 10 seconds fail -- we want to ensure it is at least 10 seconds
      // We also want to always provide at least a 2 second buffer.
      $embed_buffer = ceil(max(2, (10 - $match->duration) / 2));

      // Store the row
      $row = array(
        $match->target_media_id,
        $match->destination_media->external_id,
        $channel_metadata['channel'],
        $channel_metadata['network'],
        $channel_metadata['location'],
        $channel_metadata['scope'],
        $parsed_destination_media['program'],
        $air_time,
        $match->start,
        $match->target_start,
        $match->duration,
        "https://archive.org/details/".$match->destination_media->external_id."#start/".$match->target_start."/end/".($match->target_start + $match->duration),
        "https://archive.org/embed/".$match->destination_media->external_id."?start=".max(0,$match->target_start - $embed_buffer)."&end=".($match->target_start + $match->duration + $embed_buffer)
      );
      fputcsv($csv_file, $row);
    }
    fclose($csv_file);
  }

  function generate_seconds_csv($glued_results, $file_base) {
    global $program_length_limit;
    $seconds_cache = array(); // this will have one item per second...
    // TODO: This limit should be calculated, not set
    for($x = 0; $x < $program_length_limit; $x++) {
      $seconds_cache[$x] = array(
        "count" => 0,
        "programs" => array()
      );
    }

    // Calculate the seconds coverage
    $max_seconds = 0;
    $program_id = 0;
    foreach($glued_results as $match) {
      // Loop through the seconds and update the seconds cache
      for($x = (int)floor($match->start); $x < ceil($match->start + $match->duration); $x++) {
        $seconds_cache[$x]['count']++;
        $seconds_cache[$x]['programs'][] = $match->destination_media->external_id;
        $max_seconds = max($x, $max_seconds);
      }
      // Pull out the ID of the program
      $program_id = $match->target_media_id;
    }

    // Generate the seconds CSV
    $csv_file_seconds = fopen($file_base."_timeline.csv", "w");
    $columns = array(
      "second",
      "coverage_count",
      "link"
    );
    fputcsv($csv_file_seconds, $columns);
    for($x = 0; $x < $max_seconds + 1; $x++) {
      $row = array(
        $x,
        $seconds_cache[$x]['count'],
        "https://archive.org/details/".$program_id."#start/".$x."/end/".($x + 60)
      );
      fputcsv($csv_file_seconds, $row);
    }
    fclose($csv_file_seconds);
  }

  function generate_compressed_csv($glued_results, $file_base) {
    global $program_length_limit;
    $seconds_cache = array(); // this will have one item per second...
    // TODO: This limit should be calculated, not set
    for($x = 0; $x < $program_length_limit; $x++) {
      $seconds_cache[$x] = array(
        "count" => 0,
        "programs" => array()
      );
    }

    // Calculate the seconds coverage
    $max_seconds = 0;
    $program_id = 0;
    foreach($glued_results as $match) {
      // Loop through the seconds and update the seconds cache
      for($x = (int)floor($match->start); $x < ceil($match->start + $match->duration); $x++) {
        $seconds_cache[$x]['count']++;
        $seconds_cache[$x]['programs'][] = $match->destination_media->external_id;
        $max_seconds = max($x, $max_seconds);
      }

      // Pull out the ID of the program
      $program_id = $match->target_media_id;
    }

    // Compile the "condensed" summary
    $csv_file_condensed = fopen($file_base."_summarized.csv", "w");
    $columns = array(
      "start_second",
      "duration",
      "coverage_count",
      "programs",
      "match_url"
    );
    fputcsv($csv_file_condensed, $columns);

    $cursor = -1;
    $current_count = 0;
    foreach($seconds_cache as $x => $second) {
      $count = $seconds_cache[$x]['count'];

      // Count changed: either starting or stopping a block
      if($count != $current_count) {

        if($current_count == 0) {
          // Starting
          $cursor = $x;
          $current_count = $count;
        } else {
          // Ending
          $row = array(
            $cursor,
            $x - $cursor,
            $current_count,
            implode(", ", $seconds_cache[$x-1]['programs']), // This could be buggy if the programs shifted
            "https://archive.org/details/".$program_id."#start/".$cursor."/end/".$x
          );
          fputcsv($csv_file_condensed, $row);
          $cursor = $x;
          $current_count = $count;
        }
      }
    }
    fclose($csv_file_condensed);
  }

  function run_experiment($mp3_url) {
    global $project_id;

    // Step 1: Run the comparison
    $comparison_tasks = array();
    $comparison_tasks[] = compare_program($mp3_url, $project_id);

    // Step 2: Wait for the comparisons so finish
    resolve_tasks($comparison_tasks);

    // Step 3: Load the results into json and memory
    $comparison_result_sets = array();
    foreach($comparison_tasks as $comparison_task) {
      $comparison_result_sets[] = load_results($comparison_task->id);
    }

    // Step 8: Glue the result sets
    $glued_results = glue_results($comparison_result_sets, 10, $experiment->comparison_channels);

    // Step 9: Compile the results CSVs
    $file_base = "results/csv/_".time();
    generate_raw_csv($glued_results, $file_base);
    generate_compressed_csv($glued_results, $file_base);
    generate_seconds_csv($glued_results, $file_base);
  }

  // Open the experiment
  run_experiment($mp3_url);
?>
