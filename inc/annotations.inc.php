<?php

include_once( 'errors.inc.php' );
include_once( 'db.pg.class.php' );
include_once( 'session.class.php' );
include_once( 'tools.inc.php' );
include_once( 'json.inc.php' );
include_once( 'utils.php' );

function &queryNodes( $db, $z, $top, $left, $width, $height, $zres, $zbound, $limit, $pid, $uid)
{
  
// need relation: model_of, presynaptic_to, postsynaptic_to
// need class: synapse, presynaptic terminal, postsynaptic terminal
// retrieve class ids
$syn = $db->getClassId( $pid, "synapse" );
if(!$syn) { echo makeJSON( array( 'error' => 'Can not find "synapse" class for this project' ) ); return; }

$presyn = $db->getClassId( $pid, "presynaptic terminal" );
if(!$syn) { echo makeJSON( array( 'error' => 'Can not find "presynaptic terminal" class for this project' ) ); return; }

$postsyn = $db->getClassId( $pid, "postsynaptic terminal" );
if(!$syn) { echo makeJSON( array( 'error' => 'Can not find "postsynaptic terminal" class for this project' ) ); return; }

    
// relation ids
$model_of = $db->getRelationId( $pid, "model_of" );
if(!$model_of) { echo makeJSON( array( 'error' => 'Can not find "model_of" relation for this project' ) ); return; }

$presyn_to = $db->getRelationId( $pid, "presynaptic_to" );
if(!$presyn_to) { echo makeJSON( array( 'error' => 'Can not find "presynaptic_to" relation for this project' ) ); return; }

$postsyn_to = $db->getRelationId( $pid, "postsynaptic_to" );
if(!$postsyn_to) { echo makeJSON( array( 'error' => 'Can not find "postsynaptic_to" relation for this project' ) ); return; }

  
try {
  
  $treenodes = $db->getResult(
    'SELECT treenode.id AS id,
         treenode.parent_id AS parentid,
         (treenode.location).x AS x,
         (treenode.location).y AS y,
         (treenode.location).z AS z,
         treenode.confidence AS confidence,
         treenode.user_id AS user_id,
         treenode.radius AS radius,
         ((treenode.location).z - '.$z.') AS z_diff,
         treenode_class_instance.class_instance_id AS skeleton_id
     FROM (treenode INNER JOIN relation ON (relation.relation_name = \'element_of\' AND relation.project_id = treenode.project_id))
         LEFT OUTER JOIN (treenode_class_instance
                          INNER JOIN (class_instance INNER JOIN class ON class_instance.class_id = class.id AND class.class_name = \'skeleton\')
                          ON treenode_class_instance.class_instance_id = class_instance.id)
         ON (treenode_class_instance.treenode_id = treenode.id AND treenode_class_instance.relation_id = relation.id)
     WHERE treenode.project_id = '.$pid.'
      AND (treenode.location).x >= '.$left.'
      AND (treenode.location).x <= '.( $left + $width ).'
      AND (treenode.location).y >= '.$top.'
      AND (treenode.location).y <= '.( $top + $height ).'
      AND (treenode.location).z >= '.$z.' - '.$zbound.' * '.$zres.'
      AND (treenode.location).z <= '.$z.' + '.$zbound.' * '.$zres.'
      ORDER BY parentid DESC, id, z_diff
      LIMIT '.$limit
  );
  
  if (false === $treenodes) {
    emitErrorAndExit($db, 'Failed to query treenodes.');
  }
  
  // loop over and add type
  while ( list( $key, $val) = each( $treenodes ) )
  {
    $treenodes[$key]['type'] = "treenode";
  }
  
  // retrieve locations that are synapses
  // only retrieve synapses
  $locations = $db->getResult(
    'SELECT "location"."id" AS "id",
        ("location"."location")."x" AS "x",
        ("location"."location")."y" AS "y",
        ("location"."location")."z" AS "z",
        "location"."user_id" AS "user_id",
        ( ("location"."location")."z" - '.$z.' ) AS "z_diff"
      
      FROM "location", "connector_class_instance" AS "lci", "class_instance" AS "ci", "project"
        WHERE "project"."id" = "location"."project_id" AND
            "project"."id" = '.$pid.' AND
            ("location"."location")."x" >= '.$left.' AND
            ("location"."location")."x" <= '.( $left + $width ).' AND
            ("location"."location")."y" >= '.$top.' AND
            ("location"."location")."y" <= '.( $top + $height ).' AND
            ("location"."location")."z" >= '.$z.' - '.$zbound.' * '.$zres.' AND
            ("location"."location")."z" <= '.$z.' + '.$zbound.' * '.$zres.' AND
            "location"."id" = "lci"."connector_id" AND
            "ci"."id" = "lci"."class_instance_id" AND
            "ci"."class_id" = '.$syn.'
        
        ORDER BY "id", "z_diff" LIMIT '.$limit
  );
  
  if (false === $locations) {
    emitErrorAndExit($db, 'Failed to query treenode locations.');
  }

  while ( list( $key, $val) = each( $locations ) )
  {
    $locations[$key]['type'] = "location";
    // retrieve all pre and post treenodes
    $pretreenodes = $db->getResult(
    '
SELECT "location"."id" AS "lid", "tci"."treenode_id" as "tnid", "ci"."name" as "lcname" , "ci2"."name" AS "tcname"
FROM location, connector_class_instance as lci, treenode_class_instance as tci, class_instance as ci, class_instance as ci2, class_instance_class_instance as cici where
location.id = '.$val['id'].' and lci.connector_id = location.id
and lci.relation_id = '.$model_of.' and lci.class_instance_id = ci.id and ci.class_id = '.$syn.' 
and tci.relation_id = '.$model_of.' and tci.class_instance_id = ci2.id and ci2.class_id = '.$presyn.'
and cici.relation_id = '.$presyn_to.' and cici.class_instance_a = ci2.id and cici.class_instance_b = ci.id
    ');

    if (false === $pretreenodes) {
      emitErrorAndExit($db, 'Failed to query pre-treenodes.');
    }
    
    
    //echo makeJSON($pretreenodes);
    if(!empty($pretreenodes))
    {
      $locations[$key]['pre'] = array();
      while ( list( $key2, $val2) = each( $pretreenodes ) ) {
        $locations[$key]['pre'][] = $val2;
      }
    }
    
    // retrieve post nodes
    $posttreenodes = $db->getResult(
    '
SELECT "location"."id" AS "lid", "tci"."treenode_id" as "tnid", "ci"."name" as "lcname" , "ci2"."name" AS "tcname"
FROM location, connector_class_instance as lci, treenode_class_instance as tci, class_instance as ci, class_instance as ci2, class_instance_class_instance as cici where
location.id = '.$val['id'].' and lci.connector_id = location.id
and lci.relation_id = '.$model_of.' and lci.class_instance_id = ci.id and ci.class_id = '.$syn.' 
and tci.relation_id = '.$model_of.' and tci.class_instance_id = ci2.id and ci2.class_id = '.$postsyn.'
and cici.relation_id = '.$postsyn_to.' and cici.class_instance_a = ci2.id and cici.class_instance_b = ci.id
    ');
    
    
    if (false === $posttreenodes) {
      emitErrorAndExit($db, 'Failed to query post-treenodes.');
    }
    
    //echo makeJSON($pretreenodes);
    if(!empty($posttreenodes))
    {
      $locations[$key]['post'] = array();
      while ( list( $key2, $val2) = each( $posttreenodes ) ) {
        $locations[$key]['post'][] = $val2;
      }
    }
    
    array_push($treenodes, $locations[$key]);
  }


  if (! $db->commit() ) {
    emitErrorAndExit( $db, 'Failed to commit!' );
  }
  
  return $treenodes;
  } catch (Exception $e) {
	emitErrorAndExit( $db, 'ERROR: '.$e );
}
}

function &queryAreas($db, $z, $zbound, $limit, $pid, $uid) {
      $areas = $db->getResult(
      'SELECT polygons.id AS id,
           polygons.z AS z,
           polygons.polygon AS polygon,
           polygons.user_id AS user_id,       
           (polygons.z - '.$z.') AS z_diff           
       FROM polygons 
       WHERE polygons.project_id = '.$pid.'
        AND (polygons.lbound).z >= '.$z.' - '.$zbound.'
        AND (polygons.lbound).z <= '.$z.' + '.$zbound.'
        ORDER BY id, z_diff
        LIMIT '.$limit
    );
/*
        AND ( (polygons.lbound).x >= '.$left.' OR (polygons.ubound).x >= '.$left.' )
        AND ( (polygons.lbound).x <= '.( $left + $width ).' OR (polygons.ubound).x <= '.( $left + $width ).' )
        AND ( (polygons.lbound).y >= '.$top.' OR (polygons.ubound).y >= '.$top.' )
        AND ( (polygons.lbound).y <= '.( $top + $height ).' OR (polygons.ubound).y <= '.( $top + $height ).' )
*/   
    // loop over and add type, parse polygon into numerical values
    {
    while ( list( $key, $val) = each( $areas ) )
    {
      $areas[$key]['type'] = "area";
      $polystr = $areas[$key]['polygon'];      
      $tok = strtok($polystr, '(), \t');      
      
      while ($tok !== false) {
        $xarr[] = floatval($tok);
        $tok = strtok('(), \t');
        $yarr[] = floatval($tok);
        $tok = strtok('(), \t');
      }
      $areas[$key]['x'] = $xarr;
      $areas[$key]['y'] = $yarr;
      unset($xarr);
      unset($yarr); 
     } 
    }
    
    return $areas;
}


?>
