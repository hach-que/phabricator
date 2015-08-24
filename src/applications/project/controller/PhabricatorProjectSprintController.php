<?php

final class PhabricatorProjectSprintController
  extends PhabricatorProjectController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $request = $this->getRequest();
    $user = $request->getUser();

    $query = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->needMembers(true)
      ->needWatchers(true)
      ->needImages(true)
      ->needSlugs(true);
    $id = $request->getURIData('id');
    $slug = $request->getURIData('slug');
    if ($slug) {
      $query->withSlugs(array($slug));
    } else {
      $query->withIDs(array($id));
    }
    $project = $query->executeOne();
    if (!$project) {
      return new Aphront404Response();
    }
    
    $field_list = PhabricatorCustomField::getObjectFields(
      $project,
      PhabricatorCustomField::ROLE_VIEW);
    $field_list->readFieldsFromStorage($project);
    $field_list = mpull($field_list->getFields(), null, 'getFieldKey');
    $sprint_start = idx($field_list, 'std:project:sprint-start');
    $sprint_end = idx($field_list, 'std:project:sprint-end');
    $sprint_start = $sprint_start->getProxy()->getFieldValue();
    $sprint_end = $sprint_end->getProxy()->getFieldValue(); 
    
    $nav = $this->buildIconNavView($project);
    $nav->selectFilter("sprint/{$id}/");
    
    if (!$sprint_start && !$sprint_end) {
      $nav->appendChild(id(new PHUIInfoView())
        ->setTitle('This project is not a sprint!')
        ->appendChild(pht(
          'Set the sprint start and end dates '.
          'in the project details to enable sprint tracking.')));
    } else {
      $nav->appendChild($this->buildSprintData(
        $project,
        $sprint_start,
        $sprint_end));
    }

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => $project->getName(),
      ));
  }
  
  private function traceSprint($message) {
    //print_r($message."\r\n");
  }

  private function buildSprintData($project, $sprint_start, $sprint_end) {
    $tasks = id(new ManiphestTaskQuery())
      ->setViewer($this->getViewer())
      ->withEdgeLogicPHIDs(
          PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
          PhabricatorQueryConstraint::OPERATOR_OR,
          array($project->getPHID()))
      ->execute();
  
    $xactions = id(new ManiphestTransactionQuery())
      ->setViewer($this->getViewer())
      ->withObjectPHIDs(mpull($tasks, 'getPHID'))
      ->execute();
    
    // Sort the transactions by the date they occurred.
    $xactions = msort($xactions, 'getDateCreated');
    
    // Start the point counter at 0, and run through the
    // transactions, adding and removing data points as
    // needed.
    $points = 0;
    $creep_points = 0;
    $x_axis = array($sprint_start);
    $initial_scope_axis = array(0);
    $creep_scope_axis = array(null);
    $task_last_points = array();
    $task_is_open = array();
    $task_point_allocation = array();
    $task_assigned_to_user = array();
    $tasks_completed_by_user = array();
    $max_initial_points = 0;
    $points_closed_before_grace = 0;
    $grace_end = $sprint_start + phutil_units('1 day in seconds');
    $inserted_grace_point = false;
    $current_date_created = 0;
    $closed_points = 0;
    $closed_creep_points = 0;
    $created_points = 0;
    $created_creep_points = 0;
    $deleted_points = 0;
    $deleted_creep_points = 0;
    foreach ($xactions as $xaction) {
      $date_created = (int)$xaction->getDateCreated();
      $type = $xaction->getTransactionType();
      $obj_phid = $xaction->getObjectPHID();
      
      $this->traceSprint($obj_phid." XACT ".$type." DATE ".$date_created);
      
      if ($date_created < $current_date_created) {
        $this->traceSprint($obj_phid." !! skipping transaction due to before date created");
        continue;
      }
      
      $metadata = $xaction->getMetadata();
      
      $current_date_created = $date_created;
    
      if (!$inserted_grace_point && $current_date_created > $grace_end) {
        $x_axis[] = (int)$grace_end;
        $initial_scope_axis[] = last($initial_scope_axis);
        $creep_scope_axis[] = last($creep_scope_axis);
        $inserted_grace_point = true;
      }
    
      $this->traceSprint($obj_phid.' :: '.$type);
      if ($type === 'title') {
        if ($xaction->getOldValue() === null) {
          // Task created, it is now open.
          $task_is_open[$obj_phid] = true;
          
          // Points changed while task is open
          $old_points = 0;
          $new_points = 1;
          $diff = $new_points - $old_points;
          $alloc = idx($task_point_allocation, $obj_phid);
          if ($alloc === null) {
            $alloc = array(
              'planned' => 0,
              'creep' => 0,
            );
          }
          if ($diff < 0) {
            $creep_reduce = min($alloc['creep'], -$diff);
            $alloc['creep'] -= $creep_reduce;
            $alloc['planned'] -= (-$diff) - $creep_reduce;
            $creep_points -= $creep_reduce;
            $points -= (-$diff) - $creep_reduce;
            $deleted_points += (-$diff) - $creep_reduce;
            $deleted_creep_points += $creep_reduce;
            $this->traceSprint($obj_phid.' -- reduced by '.-$diff);
          } else if ($current_date_created > $grace_end) {
            $alloc['creep'] += $diff;
            $created_creep_points += $diff;
            $creep_points += $diff;
            $this->traceSprint($obj_phid.' -- increased by '.$diff);
          } else {
            $alloc['planned'] += $diff;
            $created_points += $diff;
            $points += $diff;
            $this->traceSprint($obj_phid.' -- increased by '.$diff);
          }
          $task_point_allocation[$obj_phid] = $alloc;
          $x_axis[] = $current_date_created;
          $initial_scope_axis[] = $points;
          $creep_scope_axis[] = ($creep_points === 0) ? null : $creep_points;
          
          $task_last_points[$obj_phid] = $new_points;
          $this->traceSprint($obj_phid.' set '.$new_points);
        }
      } else if ($type === 'reassign') {
        $task_assigned_to_user[$obj_phid] = $xaction->getNewValue();
      } else if ($type === 'status' || $type === 'core:edge') {
        $action = null;
        
        if ($type === 'core:edge') {
          foreach ($xaction->getNewValue() as $edge) {
            if (idx($edge, 'type') == PhabricatorProjectObjectHasProjectEdgeType::EDGECONST) {
              if (idx($edge, 'dst') === $project->getPHID()) {
                $action = 'added';
              }
            }
          }
          foreach ($xaction->getOldValue() as $edge) {
            if (idx($edge, 'type') == PhabricatorProjectObjectHasProjectEdgeType::EDGECONST) {
              if (idx($edge, 'dst') === $project->getPHID()) {
                $action = 'removed';
              }
            }
          }
        } else if ($type === 'status') {
          if ($xaction->getNewValue() !== 'open') {
            if (idx($task_is_open, $obj_phid, false)) {
              $action = 'removed';
              $alloc = idx($task_point_allocation, $obj_phid);
              if ($alloc === null) {
                $alloc = array(
                  'planned' => 0,
                  'creep' => 0,
                );
              }
              $allocated_points = $alloc['planned'] + $alloc['creep'];
              $tasks_completed_by_user[$obj_phid] = array(
                'completer' => idx($task_assigned_to_user, $obj_phid),
                'points' => $allocated_points
              );
            }
          } else {
            if (!idx($task_is_open, $obj_phid, false)) {
              $action = 'added';
              unset($tasks_completed_by_user[$obj_phid]);
            }
          }
        }
        
        $this->traceSprint($obj_phid.' -- action is '.$action);
      
        if ($action === 'removed') {
          // Currently open, moving to closed.
          $p = idx($task_last_points, $obj_phid, null);
          if ($p !== null) {
            $alloc = idx($task_point_allocation, $obj_phid);
            if ($alloc === null) {
              $alloc = array(
                'planned' => 0,
                'creep' => 0,
              );
            }
            
            $this->traceSprint($obj_phid.' -- reduced by '.($alloc['planned']+$alloc['creep']));
            
            $points -= $alloc['planned'];
            $creep_points -= $alloc['creep'];
            $closed_points += $alloc['planned'];
            $closed_creep_points += $alloc['creep'];
            $deleted_points += $alloc['planned'];
            $deleted_creep_points += $alloc['creep'];
            if ($current_date_created < $grace_end) {
              $points_closed_before_grace += $alloc['planned'];
            }
            $alloc['planned'] = 0;
            $alloc['creep'] = 0;
            $task_point_allocation[$obj_phid] = $alloc;
            $x_axis[] = $current_date_created;
            $initial_scope_axis[] = $points;
            $creep_scope_axis[] = ($creep_points === 0) ? null : $creep_points;
          } else {
            $this->traceSprint($obj_phid.' -- no points set on removed');
          }
          $task_is_open[$obj_phid] = false;
        } else if ($action === 'added') {
          // Currently closed, moving to open.
          $p = idx($task_last_points, $obj_phid, null);
          if ($p !== null) {
            $alloc = idx($task_point_allocation, $obj_phid);
            if ($alloc === null) {
              $alloc = array(
                'planned' => 0,
                'creep' => 0,
              );
            }
            
            if ($current_date_created > $grace_end) {
              $alloc['creep'] += $p;
              $created_creep_points += $p;
              $creep_points += $p;
            } else {
              $alloc['planned'] += $p;
              $created_points += $p;
              $points += $p;
            }
          
            $this->traceSprint($obj_phid.' -- increased by '.$p.' now at '.($alloc['creep'] + $alloc['planned']));
          
            $task_point_allocation[$obj_phid] = $alloc;
            $x_axis[] = $current_date_created;
            $initial_scope_axis[] = $points;
            $creep_scope_axis[] = ($creep_points === 0) ? null : $creep_points;
          } else {
            $this->traceSprint($obj_phid.' -- no points set on added');
          }
          $task_is_open[$obj_phid] = true;
        } else {
          $this->traceSprint($obj_phid.' -- no action');
        }
      }
      
      if ($current_date_created < $grace_end) {
        if ($points > $max_initial_points) {
          $max_initial_points = $points - $points_closed_before_grace;
        }
      }
      
      $this->traceSprint($obj_phid.' -- points is now '.($points+$creep_points));
    }
    
    // Add data point for the current time (so our predictions are more accurate).
    $current_time = time();
    if ($current_time < $sprint_end) {
      $x_axis[] = $current_time;
      $initial_scope_axis[] = $points;
      $creep_scope_axis[] = $creep_points;
    } else {
      $x_axis[] = $sprint_end;
      $initial_scope_axis[] = $points;
      $creep_scope_axis[] = $creep_points;
    }
    
    if (last($x_axis) < $grace_end) {
      $x_axis[] = $grace_end;
      $initial_scope_axis[] = $points;
      $creep_scope_axis[] = $creep_points;
    }
    
    $current_axis = array();
    $trajectory_axis = array();
    $required_axis = array();
    foreach ($x_axis as $k => $timestamp) {
      if ($timestamp < $grace_end) {
        $current_axis[] = null;
        $trajectory_axis[] = null;
        $required_axis[] = null;
      } else {
        $start_x = $grace_end;
        $start_y = $max_initial_points;
        $end_x = last($x_axis);
        $end_y = $points + $creep_points;
        $dx = $start_x - $end_x;
        $dy = $start_y - $end_y;
        if ($dx == 0) {
          $rate = 0;
        } else {
          $rate = $dy / (float)$dx;
        }
        $point = $start_y + ($rate * ($timestamp - $start_x));
        $current_axis[] = $point;
        if ($k === last_key($x_axis)) {
          $trajectory_axis[] = $point;
          $required_axis[] = $point;
        } else {
          $trajectory_axis[] = null;
          $required_axis[] = null;
        }
      }
    }
    
    $on_track = false;
    $finished = last($x_axis) == $sprint_end;
    $prediction = 0;
    if (last($x_axis) <= $sprint_end) {
      $start_x = $grace_end;
      $start_y = $max_initial_points;
      $end_x = last($x_axis);
      $end_y = $points + $creep_points;
      $dx = $start_x - $end_x;
      $dy = $start_y - $end_y;
      if ($dx == 0) {
        $rate = 0;
      } else {
        $rate = $dy / (float)$dx;
      }
      $prediction = $start_y + ($rate * ($sprint_end - $start_x));
      if ($prediction <= 0 && $rate != 0) {
        // We are predicting we'll finish all the work before
        // the end of the sprint, so recalculate the expected
        // end point.
        //
        // 0 = $start_y + ($rate * ($date - $start_x));
        // -$start_y = $rate * ($date - $start_x);
        // (-$start_y) / $rate = $date - $start_x;
        // ((-$start_y) / $rate) + $start_x = $date
        $on_track = true;
        $predicted_time = ((-$start_y) / $rate) + $start_x;
        
        if (!$finished) {
          $x_axis[] = (int)$predicted_time;
          $initial_scope_axis[] = null;
          $creep_scope_axis[] = null;
          $current_axis[] = null;
          $trajectory_axis[] = 0;
          $required_axis[] = null;
        }
      }
      
      if (!$finished) {
        $x_axis[] = (int)$sprint_end;
        $initial_scope_axis[] = null;
        $creep_scope_axis[] = null;
        $current_axis[] = null;
        $trajectory_axis[] = ($prediction < 0) ? null : $prediction;
        $required_axis[] = 0;
      }
    }
  
    $id = celerity_generate_unique_node_id();
    $chart = phutil_tag(
      'div',
      array(
        'id' => $id,
        'style' => 'border: 1px solid #BFCFDA; '.
                   'background-color: #fff; '.
                   'margin: 8px 16px; '.
                   'height: 400px; ',
      ),
      '');

    list($burn_x, $burn_y) = array(
      array(
        0,
        60,
        120,
      ),
      array(
        60,
        55,
        25,
      ));

    require_celerity_resource('raphael-core');
    require_celerity_resource('raphael-g');
    require_celerity_resource('raphael-g-line');
    
    Javelin::initBehavior('stacked-line-chart', array(
      'hardpoint' => $id,
      'x' => array(
        $x_axis,
      ),
      'y' => array(
        $initial_scope_axis,
        $creep_scope_axis,
        $current_axis,
        $trajectory_axis,
        $required_axis,
      ),
      'xformat' => 'epoch',
      'yformat' => 'int',
      'colors' => array(
        '#2980b9',
        '#b98029',
        '#ccc',
        '#cfc',
        '#fcc',
      ),
      'stacked' => array(
        true,
        true,
        false,
        false,
        false,
      ),
      'lead' => array(
        true,
        true,
        false,
        false,
        false,
      ),
    ));
    
    $header_planning = id(new PHUIHeaderView())
      ->setHeader(pht('Sprint Plan'));
      
    if ($finished) {
      $header_progress = id(new PHUIHeaderView())
        ->setHeader(pht('Sprint Results'));
    } else {
      $header_progress = id(new PHUIHeaderView())
        ->setHeader(pht('Sprint Progression'));
    }
      
    $header_prediction = id(new PHUIHeaderView())
      ->setHeader(pht('Sprint Prediction'));
      
    $day_length = (int)ceil(($sprint_end - $sprint_start) / phutil_units('1 day in seconds'));
    $required_planned_velocity = round($max_initial_points / (float)$day_length, 2);
    $current_velocity = round(($max_initial_points - ($points + $creep_points)) / (float)$day_length, 2);
    
    if (time() < $sprint_end) {
      $rem_length = (int)ceil(($sprint_end - time()) / phutil_units('1 day in seconds'));
      $required_remaining_velocity = round(($points + $creep_points) / $rem_length, 2);
    } else {
      $required_remaining_velocity = 0;
    }
    
    if ($created_points > 0 || $created_creep_points > 0) {
      $foresight = $created_creep_points / (float)($created_points + $created_creep_points);
      $foresight = (1 - $foresight) * 100;
      $foresight = round($foresight) . '%';
    } else {
      $foresight = '-';
    }
    
    if ($closed_points > 0 || $closed_creep_points > 0) {
      $focus = $closed_creep_points / (float)($closed_points + $closed_creep_points);
      $focus = (1 - $focus) * 100;
      $focus = round($focus) . '%';
    } else {
      $focus = '-';
    }
    
    if ($created_points + $created_creep_points === 0) {
      $completion = 0;
      $predicted_completion = 0;
    } else {
      $completion = 100 - (int)round(($points + $creep_points) / (float)($created_points + $created_creep_points) * 100);
      if ($completion > 100) {
        $completion = 100;
      } else if ($completion < 0) {
        $completion = 0;
      }
      
      $predicted_completion = 100 - (int)round($prediction / (float)($created_points + $created_creep_points) * 100);
      if ($predicted_completion > 100) {
        $predicted_completion = 100;
      } else if ($predicted_completion < 0) {
        $predicted_completion = 0;
      }
    }
    
    $column_planning = id(new PHUIInfoPanelView())
      ->setHeader($header_planning)
      ->setColumns(2)
      ->addInfoBlock($max_initial_points, 'Initial Scope (tasks)')
      ->addInfoBlock($required_planned_velocity, 'Planned Velocity (tasks / day)')
      ->addInfoBlock($created_creep_points, 'Scope Creep (unplanned tasks)')
      ->addInfoBlock($foresight, 'Foresight (% of scope planned)');
    
    $column_progress = id(new PHUIInfoPanelView())
      ->setHeader($header_progress)
      ->setColumns(2)
      ->setProgress($completion)
      ->addInfoBlock($points + $creep_points, 'Total Points Remaining')
      ->addInfoBlock($current_velocity, 'Current Velocity (tasks / day)')
      ->addInfoBlock($closed_points, 'Scope Resolved (tasks)')
      ->addInfoBlock($closed_creep_points, 'Creep Resolved (unplanned tasks)')
      ->addInfoBlock($completion.'%', 'Complete (% of tasks resolved)')
      ->addInfoBlock($focus, 'Focus (% of resolved planned tasks)');
      
    $column_prediction = id(new PHUIInfoPanelView())
      ->setHeader($header_prediction)
      ->setColumns(2)
      ->setProgress($predicted_completion)
      ->addInfoBlock(max(round($prediction, 2), 0), 'Scope Unworked (est. tasks at end)')
      ->addInfoBlock($required_remaining_velocity, 'Required Velocity (tasks / day)')
      ->addInfoBlock($predicted_completion.'%', 'Est. Completion (% of est tasks resolved)');
      
    if ($finished) {
      $status = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->appendChild(pht('This sprint has ended.'));
    } else if ($on_track) {
      $status = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_SUCCESS)
        ->appendChild(pht('This sprint is on track for completion.'));
    } else {
      $status = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
        ->appendChild(pht('This sprint will currently not be completed on time.'));
    }
    
    $user_lookup = ipull($tasks_completed_by_user, 'completer');
    if (empty($user_lookup)) {
      $user_lookup = array();
    } else {
      $user_lookup = id(new PhabricatorPeopleQuery())
        ->setViewer($this->getViewer())
        ->withPHIDs($user_lookup)
        ->execute();
    }
    $user_lookup = mpull($user_lookup, null, 'getPHID');
    
    $header_user_percentage_complete= id(new PHUIHeaderView())
      ->setHeader(pht('%% Tasks Completed By User'));
      
    $header_user_total_complete= id(new PHUIHeaderView())
      ->setHeader(pht('Total Tasks Completed By User'));
      
    $column_user_percentage_complete = id(new PHUIInfoPanelView())
      ->setHeader($header_user_percentage_complete)
      ->setColumns(3);
      
    $column_user_total_complete = id(new PHUIInfoPanelView())
      ->setHeader($header_user_total_complete)
      ->setColumns(3);
      
    $tasks_completed_by_user = igroup($tasks_completed_by_user, 'completer');
    $user_points = array();
    $total_points = 0;
    foreach ($tasks_completed_by_user as $user => $entries) {
      if ($user === null) {
        continue;
      }
      $sum = 0;
      foreach ($entries as $entry) {
        $sum += idx($entry, 'points');
      }
      $user_points[$user] = $sum;
      $total_points += $sum;
    }
    arsort($user_points);
    foreach ($user_points as $user => $points) {
      $user_obj = idx($user_lookup, $user);
      if ($user_obj === null) {
        $user_link = pht('Unassigned');
      } else {
        $user_link = phutil_tag(
          'a',
          array('href' => '/p/'.$user_obj->getUsername()),
          $user_obj->getUsername()
        );
      }
      $column_user_total_complete->addInfoBlock($points, $user_link);
      $points_perc = round(($points / (float)$total_points) * 100);
      $column_user_percentage_complete->addInfoBlock($points_perc.'%', $user_link);
    }
    
    $layout = id(new AphrontMultiColumnView());
    $layout->addColumn($column_planning);
    $layout->addColumn($column_progress);
    if (!$finished) {
      $layout->addColumn($column_prediction);
    }
    $layout->setFluidLayout(true);
    
    $layout2 = id(new AphrontMultiColumnView());
    $layout2->addColumn($column_user_percentage_complete);
    $layout2->addColumn($column_user_total_complete);
    $layout2->setFluidLayout(true);

    return array($status, $chart, $layout, $layout2);
  }
 
}
