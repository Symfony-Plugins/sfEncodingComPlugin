<?php
/**
 * @package sfEncodingComPlugin
 * @subpackage lib
 * @author Stephen Ostrow <sostrow@sowebdesigns.com>
 * @version SVN: $Id$
 */
class sfEncodingCom {

  /**
   * The url to send the video to
   *
   * @var string
   */
  private $url = 'http://manage.encoding.com/';

  /**
   * an unique user identifier
   *
   * Can be taken from the user's page
   *
   * @var string
   */
  private $userid;

  /**
   * user's key string
   *
   * Creates automatically when user created and can be changed by user.
   *
   * @var string
   */
  private $userkey;

  /**
   * the action to be performed
   *
   * * Possible Values:
   *  * AddMedia – add new media to user’s media list, creates new items in a queue according to formats specified in XML
   *  * AddMediaBenchmark - add new media to user's media list and set a flag for NOT processing it after downloading. Format fields could be specified as well. If NotifyURL is set, a notification will be sent after the media will have been ready for processing. Note: The media will get 'Ready to process' status only if at least one <format> was specified.
   *  * UpdateMedia – replace information about existing media's formats. All old format items will be deleted, and the new ones added.
   *  * ProcessMedia - start encoding of previously downloaded media (one that added with AddMediaBenchmark action)
   *  * CancelMedia – delete specified media and all its items in queue
   *  * GetMediaList – return list of user’s media
   *  * GetStatus – return information about selected user’s media and all its items in queue
   *  * GetMediaInfo - returns some video parameters of the specified media, if available
   *
   * @var string
   */
  private $action = 'AddMedia';

  /**
   * an unique identifier of media.
   *
   * This field must be specified for the following actions: UpdateMedia, CancelMedia, GetStatus.
   *
   * @var string
   */
  private $mediaid;

  /**
   * The source location of the video to be encoded
   *
   * It can be in the following formats:
   *
   *  * http://[user]:[password]@ [server]/[path]/[filename]
   *  * ftp://[user]:[password]@[server]/[path]/[filename]
   *  * sftp://[user]:[password]@[server]/[path]/[filename]
   *  * http://[AWS_KEY:AWS_PWD@][bucket].s3.amazonaws.com/[filename]
   *   * Note: do not forget to encode your AWS_SECRET, specifically replace '/ ' with '%2F'.
   *   * If you don't specify AWS key/secret, the object must have READ permission for AWS user 1a85ad8fea02b4d948b962948f69972a72da6bed800a7e9ca7d0b43dc61d5869
   *
   * @var string
   */
  private $source;

  /**
   * The url to notify after encoding is completed
   *
   * could be either an HTTP(S) URL of the script the result would be posted to,
   * or a mailto: link with email address the result info to be sent. This field
   * may be specified for AddMedia and AddMediaBenchmark actions.
   *
   * @var string
   */
  private $notify_url;

  /**
   * The formats for the source to be encoded as
   *
   * @var array
   */
  private $formats = array();

  /**
   * Contains the default format parameters if not given
   *
   * @see http://www.encoding.com/wdocs/ApiDoc
   *
   * @var array
   */
  protected $default_format_options = array(
    'output'            => null,  # Output Format - REQUIRED
    'video_codec'       => null,  # Video Codec
    'audio_codec'       => null,  # Audio Codec
    'bitrate'           => null,  # Video Bitrate
    'audio_bitrate'     => null,  # Audio Bitrate
    'audio_sample_rate' => null,  # Audio Sample Rate
    'size'              => null,  # Size
    'crop_left'         => null,  # Crop Left
    'crop_top'          => null,  # Crop Top
    'crop_right'        => null,  # Crop Right
    'crop_bottom'       => null,  # Crop Bottom
    'thumb_time'        => null,  # Thumb time
    'thumb_size'        => null,  # Thumb size
    'add_meta'          => 'yes', # Add meta data
    'rc_init_occupancy' => null,  # RC Occupancy
    'minrate'           => null,  # Min Rate
    'maxrate'           => null,  # Max Rate
    'bufsize'           => null,  # RC Buffer Size
    'keyframe'          => null,  # Keyframe Period (GOP)
    'start'             => null,  # Start From
    'duration'          => null,  # Result Duration
    'destination'       => null,  # Destination File
    'thumb_destination' => null,  # Thumbnail Destination
    'logo'              => array(
      'logo_source'     => null,  # Logo URL
      'logo_x'          => null,  # Logo Left
      'logo_y'          => null,  # Logo Top
    ),
  );

  /**
   * Curl option for return transfer
   *
   * @var boolean
   */
  private $return_transfer = 1;

  /**
   * Curl option for header
   *
   * @var boolean
   */
  private $header = 0;

  /**
   * The request
   *
   * @var SimpleXMLElement
   */
  private $request;

  /**
   * The response sent back
   *
   * @var SimpleXMLElement
   */
  private $response;

  /**
   * The error if any when returning from the request
   *
   * @var string
   */
  private $error;

  /**
   * The message if any when returning from the request
   *
   * @var unknown_type
   */
  private $message;

  /**
   * The constructor for sfEncodingCom
   *
   */
  public function __construct()
  {
    if ( !extension_loaded('curl') ) {
      throw new sfException('The curl extension for php must be loaded');
    }
    $this->userid = sfConfig::get('app_sf_encoding_com_plugin_userid');
    $this->userkey = sfConfig::get('app_sf_encoding_com_plugin_userkey');
  }

  /**
   * Returns the request object
   *
   * @return SimpleXMLElement the request object
   */
  public function getRequest()
  {
    return $this->request;
  }

  /**
   * Updates the request object from the current settings
   *
   * @returns sfSimpleXMLElement the request
   *
   */
  public function updateRequest()
  {
    $this->request = new SimpleXMLElement('<?xml version="1.0"?><query></query>');
    $this->request->addChild('userid', $this->userid);
    $this->request->addChild('userkey', $this->userkey);
    $this->request->addChild('action', $this->action);
    $this->request->addChild('source', $this->source);

    if ( $this->notify_url ) {
      $this->request->addChild('notify', $this->notify_url);
    }

    if ( $this->mediaid ) {
      $this->request->addChild('mediaid', $this->mediaid);
    }

    foreach ( $this->formats as $format ) {
      $formatNode = $this->request->addChild('format');
      // Format fields
      foreach($format as $property => $value)
      {
        if (is_string($value) && $value !== '') {
          $formatNode->addChild($property, $value);
        } else if ( is_array($value)) {
          // Process logo
        }
      }
    }

    return $this->getRequest();
  }

  /**
   * Returns the current request as xml
   *
   * @return string the current response in xml
   */
  public function getXml()
  {
    return $this->getRequest()->asXML();
  }

  /**
   * Sends the request and write the error, message, and request back
   *
   */
  public function send()
  {
    if ( !strlen( $this->getSource()) ) {
      throw new sfException('You must supply a source video');
    }

    if ( ! count($this->getFormats()) ) {
      throw new sfException('You have not supplied any formats for the source to be encoded to');
    }

    $this->updateRequest();

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "xml=" . urlencode($this->getXml()));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, $this->return_transfer);
    curl_setopt($ch, CURLOPT_HEADER, $this->header);
    $resp = curl_exec($ch);

    try
    {
      // Creating new object from response XML
      $this->response = new SimpleXMLElement($resp);

      // If there are any errors, set error message
      if(isset($this->response->errors[0]->error[0])) {
        $this->error = $this->response->errors[0]->error[0] . '';
      }
      else
      if ($this->response->message[0]) {
        // If message received, set OK message
        $this->message = $this->response->message[0] . '';
      }
    }
    catch(Exception $e)
    {
      // If wrong XML response received
      $this->error = $e->getMessage();
    }
  }

  /**
   * Sets the userid for encoding.com
   *
   * @param string $userid
   */
  public function setUserid($userid)
  {
    $this->userid = $userid;
  }

  /**
   * Gets the userid for encoding.com
   *
   * @return string the userid
   */
  public function getUserid()
  {
    return $this->userid;
  }

  /**
   * Sets the the userkey
   *
   * @param string $userkey
   */
  public function setUserkey($userkey)
  {
    $this->userkey = $userkey;
  }

  /**
   * Returns the userkey
   *
   * @return string
   */
  public function getUserkey()
  {
    return $this->userkey;
  }

  /**
   * Returns whether or not there is an error after the response
   *
   * @return boolean has error
   */
  public function hasError()
  {
    return strlen($this->error) ? true : false;
  }

  /**
   * Returns the error of the response
   *
   * @return string the error
   */
  public function getError()
  {
    return $this->error;
  }

  /**
   * Returns whether or not there is a message after the response
   *
   * @return boolean has message
   */
  public function hasMessage()
  {
    return strlen($this->message) ? true : false;
  }

  /**
   * Returns the message returned from the response
   *
   * @return string the message
   */
  public function getMessage()
  {
    return $this->message;
  }

  /**
   * Adds a format to the request
   *
   * @see $this->default_format_options
   *
   * @param array $format_options
   */
  public function addFormat($format_options = array() )
  {
    $this->formats[] = array_merge( $this->default_format_options, $format_options );
  }

  /**
   * Sets the action
   *
   * @see $this->action
   *
   * @param string $action
   */
  public function setAction($action)
  {
    $this->action = $action;
  }

  /**
   * Returns the action
   *
   * @return string the action
   */
  public function getAction()
  {
    return $this->action;
  }

  /**
   * Sets the source
   *
   * @see $this->source
   *
   * @param string $source
   */
  public function setSource($source)
  {
    $this->source = $source;
  }

  /**
   * Returns the source
   *
   * @return string
   */
  public function getSource()
  {
    return $this->source;
  }

  /**
   * Sets the url to be called after encoding
   *
   * @see $this->notify_url
   *
   * @param string $notify_url
   */
  public function setNotifyUrl($notify_url)
  {
    $this->notify_url = $notify_url;
  }

  /**
   * Returns the url to be call after encoding
   *
   * @return string
   */
  public function getNotifyUrl()
  {
    return $this->notify_url;
  }

  /**
   * Returns the formats array
   *
   * @return array
   */
  public function getFormats()
  {
    return $this->formats;
  }

}
