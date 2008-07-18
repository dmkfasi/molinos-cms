<?php

require(dirname(__FILE__) .'/../lib/bootstrap.php');

try {
  mcms::db()->beginTransaction();

  $ids = mcms::db()->getResultsK("id",
    "SELECT `id`, `parent_id` FROM `node` "
    ."WHERE `parent_id` IS NOT NULL OR `id` IN "
    ."(SELECT `parent_id` FROM `node`) "
    ."ORDER BY `left`");

  printf("Found %d nodes, rebuilding tree.\n", count($ids));
  $new = build_tree($ids);

  printf("Resetting borders.\n");
  mcms::db()->exec("UPDATE `node` SET `left` = NULL, `right` = NULL");
  $sth = mcms::db()->prepare("UPDATE `node` SET `left` = ?, `right` = ? "
    ."WHERE `id` = ?");
  foreach ($new as $k => $v)
    $sth->execute(array($v['left'], $v['right'], $v['id']));

  printf("Done, committing.\n");

  mcms::db()->commit();
} catch (PDOException $e) {
  printf("DB ERROR: %s\n", trim($e->getMessage()));
  mcms::db()->rollback();
} catch (Exception $e) {
  printf("ERROR: %s\n", trim($e->getMessage()));
  mcms::db()->rollback();
}

function build_tree($data)
{
  $avail = 1;

  foreach ($data as $k => $v) {
    if (array_key_exists($p = $v['parent_id'], $data)) {
      $left = $data[$p]['right'];
    } else {
      $left = $avail;
    }

    foreach ($data as $k1 => $v1) {
      if ($v1['left'] >= $left)
        $data[$k1]['left'] += 2;
      if ($v1['right'] >= $left)
        $data[$k1]['right'] += 2;
    }

    $avail += 2;

    $data[$k]['left'] = $left;
    $data[$k]['right'] = $left + 1;
  }

  return $data;
}
