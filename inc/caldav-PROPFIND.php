<?php
/**
* CalDAV Server - handle PROPFIND method
*
* @package   davical
* @subpackage   propfind
* @author    Andrew McMillan <andrew@catalyst.net.nz>
* @copyright Catalyst .Net Ltd, Andrew McMillan
* @license   http://gnu.org/copyleft/gpl.html GNU GPL v2 or later
*/
dbg_error_log('PROPFIND', 'method handler');

if ( ! ($request->AllowedTo('read') || $request->AllowedTo('freebusy') || $request->AllowedTo('read-current-user-privilege-set') ) ) {
  dbg_error_log('ERROR','Insufficient privileges for "%s" of "%s"', $request->path, implode(', ', $request->permissions) );
  $request->DoResponse( 403, translate('You may not access that collection') );
}

require_once('iCalendar.php');
require_once('XMLDocument.php');
require_once('DAVResource.php');

$reply = new XMLDocument( array( 'DAV:' => '' ) );

if ( !isset($request->xml_tags) ) {
  $request->DoResponse( 403, translate("REPORT body contains no XML data!") );
}
$position = 0;
$xmltree = BuildXMLTree( $request->xml_tags, $position);
if ( !is_object($xmltree) ) {
  $request->DoResponse( 403, translate("REPORT body is not valid XML data!") );
}
$allprop    = $xmltree->GetPath('/DAV::propfind/*');
$property_list = array();
foreach( $allprop AS $k1 => $propwrap ) {
  switch ( $propwrap->GetTag() ) {
    case 'DAV::allprop':
      $property_list[] = 'DAV::allprop';
      break;
    case 'DAV::propname':
      $property_list[] = 'DAV::propname';
      break;
    default:  // prop, include
      $subprop = $propwrap->GetElements();
      foreach( $subprop AS $k => $v ) {
        $property_list[] = $v->GetTag();
      }
  }
}


/**
 * Add the calendar-proxy-read/write pseudocollections
 * @param responses array of responses to which to add the collections
 */
function add_proxy_response( &$responses, $which, $parent_path ) {
  global $request, $reply, $c, $session, $property_list;

  if ($parent_path != '/'.$request->principal->username.'/') {
    return; // Nothing to proxy for
  }

  $collection = (object) '';
  if ( $which == 'read' ) {
    $proxy_group = $request->principal->ReadProxyGroup();
  } else if ( $which == 'write' ) {
    $proxy_group = $request->principal->WriteProxyGroup();
  }

  $collection->dav_name = $parent_path.'calendar-proxy-'.$which.'/';
  $collection->is_calendar    = 'f';
  $collection->is_addressbook = 'f';
  $collection->is_principal   = 't';
  $collection->is_proxy       = 't';
  $collection->proxy_type     = $which;
  $collection->type           = 'proxy';
  $collection->dav_displayname = $collection->dav_name;
  $collection->collection_id = 0;
  $collection->user_no = $session->user_no;
  $collection->username = $session->username;
  $collection->email = $session->email;
  $collection->created = date('Ymd\THis');
  $collection->dav_etag = md5($c->system_name . $collection->dav_name . implode($proxy_group) );
  $collection->proxy_for = $proxy_group;
  $collection->resourcetypes  = sprintf('<DAV::collection/><http://calendarserver.org/ns/:calendar-proxy-%s/>', $which);
  $collection->in_freebusy_set = 'f';
  $collection->schedule_transp = 'transp';
  $collection->timezone        = null;
  $collection->description     = '';

  $resource = new DAVResource($collection);
  $resource->FetchPrincipal();
  $responses[] = $resource->RenderAsXML($property_list, $reply);

}


/**
* Get XML response for items in the collection
* If '/' is requested, a list of visible users is given, otherwise
* a list of calendars for the user which are parented by this path.
*/
function get_collection_contents( $depth, $user_no, $collection ) {
  global $c, $session, $request, $reply, $property_list;

  dbg_error_log('PROPFIND','Getting collection contents: Depth %d, User: %d, Path: %s', $depth, $user_no, $collection->dav_name() );

  $date_format = iCalendar::HttpDateFormat();
  $responses = array();
  if ( ! $collection->IsCalendar() &&  ! $collection->IsAddressbook() ) {
    /**
    * Calendar/Addressbook collections may not contain collections, so we won't look
    */
    $params = array( ':session_principal' => $session->principal_id, ':scan_depth' => $c->permission_scan_depth );
    if ( $collection->dav_name() == '/' ) {
      $sql = "SELECT usr.*, '/' || username || '/' AS dav_name, md5(username || updated::text) AS dav_etag, ";
      $sql .= "to_char(joined at time zone 'GMT',$date_format) AS created, ";
      $sql .= "to_char(updated at time zone 'GMT',$date_format) AS modified, ";
      $sql .= 'FALSE AS is_calendar, TRUE AS is_principal, FALSE AS is_addressbook, \'principal\' AS type, ';
      $sql .= 'principal_id AS collection_id, ';
      $sql .= 'principal.* ';
      $sql .= 'FROM usr JOIN principal USING (user_no) ';
      $sql .= "WHERE (pprivs(:session_principal::int8,principal.principal_id,:scan_depth::int) & 1::BIT(24))::INT4::BOOLEAN ";
      $sql .= 'ORDER BY usr.user_no';
    }
    else {
      $sql = 'SELECT principal.*, collection.*, \'collection\' AS type FROM collection LEFT JOIN principal USING (user_no) ';
      $sql .= 'WHERE parent_container = :this_dav_name ';
      $sql .= "AND (path_privs(:session_principal::int8,collection.dav_name,:scan_depth::int) & 1::BIT(24))::INT4::BOOLEAN ";
      $sql .= ' ORDER BY collection_id';
      $params[':this_dav_name'] = $collection->dav_name();
    }
    $qry = new AwlQuery($sql, $params);

    if( $qry->Exec('PROPFIND',__LINE__,__FILE__) && $qry->rows() > 0 ) {
      while( $subcollection = $qry->Fetch() ) {
        $resource = new DAVResource($subcollection);
        $responses[] = $resource->RenderAsXML($property_list, $reply);
      }
    }

    if ( $collection->IsPrincipal() == 't' ) {
      // Caldav Proxy: 5.1 par. 2: Add child resources calendar-proxy-(read|write)
      dbg_error_log('PROPFIND','Adding calendar-proxy-read and write. Path: %s', $collection->dav_name() );
      add_proxy_response($responses, 'read', $collection->dav_name() );
      add_proxy_response($responses, 'write', $collection->dav_name() );
    }
  }

  /**
  * freebusy permission is not allowed to see the items in a collection.  Must have at least read permission.
  */
  if ( $request->AllowedTo('read') ) {
    dbg_error_log('PROPFIND','Getting collection items: Depth %d, User: %d, Path: %s', $depth, $user_no, $collection->dav_name() );
    $privacy_clause = ' ';
    if ( ! $request->AllowedTo('all') ) {
      $privacy_clause = " AND (calendar_item.class != 'PRIVATE' OR calendar_item.class IS NULL) ";
    }

    $time_limit_clause = ' ';
    if ( isset($c->hide_older_than) && intval($c->hide_older_than > 0) ) {
      $time_limit_clause = " AND calendar_item.dtstart > (now() - interval '".intval($c->hide_older_than)." days') ";
    }

    $sql = 'SELECT collection.*, principal.*, caldav_data.*, caldav_data, ';
    $sql .= "to_char(coalesce(calendar_item.created, caldav_data.created) at time zone 'GMT',$date_format) AS created, ";
    $sql .= "to_char(last_modified at time zone 'GMT',$date_format) AS modified, ";
    $sql .= 'summary AS dav_displayname, ';
    $sql .= 'calendar_item.* ';
    $sql .= 'FROM caldav_data LEFT JOIN calendar_item USING( dav_id, user_no, dav_name, collection_id) ';
    $sql .= 'LEFT JOIN collection USING(collection_id,user_no) LEFT JOIN principal USING(user_no) ';
    $sql .= 'WHERE collection.dav_name = :collection_dav_name '.$time_limit_clause.' '.$privacy_clause;
    if ( isset($c->strict_result_ordering) && $c->strict_result_ordering ) $sql .= " ORDER BY caldav_data.dav_id";
    $qry = new AwlQuery( $sql, array( ':collection_dav_name' => $collection->dav_name()) );
    if( $qry->Exec('PROPFIND',__LINE__,__FILE__) && $qry->rows() > 0 ) {
      while( $item = $qry->Fetch() ) {
        $resource = new DAVResource($item);
        $responses[] = $resource->RenderAsXML($property_list, $reply);
      }
    }
  }

  return $responses;
}


/**
* Get XML response for a single collection.  If Depth is >0 then
* subsidiary collections will also be got up to $depth
*/
function get_collection( $depth, $user_no, $collection_path ) {
  global $session, $c, $request, $property_list, $reply;
  $responses = array();

  dbg_error_log('PROPFIND','Getting collection: Depth %d, User: %d, Path: %s', $depth, $user_no, $collection_path );

  if (preg_match('#/[^/]+/calendar-proxy-(read|write)/?#',$collection_path, $match) ) {
  	// this should be a direct query to /<somewhere>/calendar-proxy-<something>
  	dbg_error_log('PROPFIND','Simulating calendar-proxy-read or write. Path: %s', $collection_path);
       add_proxy_response($responses, $match[1], $collection_path);
  }

  if ( $collection_path == null || $collection_path == '' ) $collection_path = '/';

  $resource = new DAVResource($collection_path);
  if ( !$resource->Exists() ) return $responses;

  $responses[] = $resource->RenderAsXML($property_list, $reply);

  if ( $depth > 0 ) {
    dbg_error_log('PROPFIND','Getting collection contents of path: "%s"', $collection_path );
    $responses = array_merge($responses, get_collection_contents( $depth-1,  $user_no, $resource ) );
  }
  else {
    dbg_error_log('PROPFIND','Not Getting collection contents of path: "%s"', $collection_path );
  }

  return $responses;
}


/**
* Something that we can handle, at least roughly correctly.
*/
$responses = array();
if ( $request->IsProxyRequest() ) {
  add_proxy_response($responses, $request->proxy_type, '/' . $request->principal->username . '/' );
  /** Nothing inside these, as yet. */
}
elseif ( $request->IsCollection() ) {
  $responses = get_collection( $request->depth, $request->user_no, $request->path );
}
elseif ( $request->AllowedTo('read') ) {
  $resource = new DAVResource($request->path);
  $response = $resource->RenderAsXML($property_list, $reply);
  if ( isset($response) ) $responses[] = $response;
}

if ( count($responses) < 1 ) {
  if ( $request->AllowedTo('read') ) {
    $request->DoResponse( 404, translate('That resource is not present on this server.') );
  }
  else {
    $request->DoResponse( 403, translate('You do not have appropriate rights to view that resource.') );
  }
}

$xmldoc = $reply->Render('multistatus', $responses);
$etag = md5($xmldoc);
header('ETag: "'.$etag.'"');
$request->DoResponse( 207, $xmldoc, 'text/xml; charset="utf-8"' );

