<?php
// $Id$

/**
 * Test case for typical Drupal tests.
 */
class DrupalTestCase extends UnitTestCase {
  protected $_logged_in = FALSE;
  protected $_content;
  protected $plain_text;
  protected $_originalModules     = array();
  protected $_modules             = array();
  protected $_cleanupVariables    = array();
  protected $_cleanupUsers        = array();
  protected $_cleanupRoles        = array();
  protected $_cleanupNodes        = array();
  protected $_cleanupContentTypes = array();
  protected $ch;
  // We do not reuse the cookies in further runs, so we do not need a file
  // but we still need cookie handling, so we set the jar to NULL
  protected $cookie_file = NULL;
  // Overwrite this any time to supply cURL options as necessary,
  // DrupalTestCase itself never sets this but always obeys whats set.
  protected $curl_options         = array();

  /**
   * Retrieve the test information from getInfo().
   *
   * @param string $label Name of the test to be used by the SimpleTest library.
   */
  function __construct($label = NULL) {
    if (!$label) {
      if (method_exists($this, 'getInfo')) {
        $info  = $this->getInfo();
        $label = $info['name'];
      }
    }
    parent::__construct($label);
  }

  /**
   * Creates a node based on default settings.
   *
   * @param settings
   *   An assocative array of settings to change from the defaults, keys are
   *   node properties, for example 'body' => 'Hello, world!'.
   * @return object Created node object.
   */
  function drupalCreateNode($settings = array()) {
    // Populate defaults array
    $defaults = array(
      'body'      => $this->randomName(32),
      'title'     => $this->randomName(8),
      'comment'   => 2,
      'changed'   => time(),
      'format'    => FILTER_FORMAT_DEFAULT,
      'moderate'  => 0,
      'promote'   => 0,
      'revision'  => 1,
      'log'       => '',
      'status'    => 1,
      'sticky'    => 0,
      'type'      => 'page',
      'revisions' => NULL,
      'taxonomy'  => NULL,
    );
    $defaults['teaser'] = $defaults['body'];
    // If we already have a node, we use the original node's created time, and this
    if (isset($defaults['created'])) {
      $defaults['date'] = format_date($defaults['created'], 'custom', 'Y-m-d H:i:s O');
    }
    if (empty($settings['uid'])) {
      global $user;
      $defaults['uid'] = $user->uid;
    }
    $node = ($settings + $defaults);
    $node = (object)$node;

    node_save($node);

    // small hack to link revisions to our test user
    db_query('UPDATE {node_revisions} SET uid = %d WHERE vid = %d', $node->uid, $node->vid);
    $this->_cleanupNodes[] = $node->nid;
    return $node;
  }

  /**
   * Creates a custom content type based on default settings.
   *
   * @param settings
   *   An array of settings to change from the defaults.
   *   Example: 'type' => 'foo'.
   * @return object Created content type.
   */
  function drupalCreateContentType($settings = array()) {
    // find a non-existent random type name.
    do {
      $name = strtolower($this->randomName(3, 'type_'));
    } while (node_get_types('type', $name));

    // Populate defaults array
    $defaults = array(
      'type' => $name,
      'name' => $name,
      'description' => '',
      'help' => '',
      'min_word_count' => 0,
      'title_label' => 'Title',
      'body_label' => 'Body',
      'has_title' => 1,
      'has_body' => 1,
    );
    // imposed values for a custom type
    $forced = array(
      'orig_type' => '',
      'old_type' => '',
      'module' => 'node',
      'custom' => 1,
      'modified' => 1,
      'locked' => 0,
    );
    $type = $forced + $settings + $defaults;
    $type = (object)$type;

    node_type_save($type);
    node_types_rebuild();

    $this->_cleanupContentTypes[] = $type->type;
    return $type;
  }

  /**
   * Get a list files that can be used in tests.
   *
   * @param string $type File type, possible values: 'binary', 'html', 'image', 'javascript', 'php', 'sql', 'text'.
   * @param integer $size File size in bytes to match. Please check the tests/files folder.
   * @return array List of files that match filter.
   */
  function drupalGetTestFiles($type, $size = NULL) {
    $files = array();

    // Make sure type is valid.
    if (in_array($type, array('binary', 'html', 'image', 'javascript', 'php', 'sql', 'text'))) {
      $path = file_directory_path() .'/simpletest';
      $files = file_scan_directory($path, $type .'\-.*');

      // If size is set then remove any files that are not of that size.
      if ($size !== NULL) {
        foreach ($files as $file) {
          $stats = stat($file->filename);
          if ($stats['size'] != $size) {
            unset($files[$file->filename]);
          }
        }
      }
    }
    return $files;
  }

  /**
   * Generates a random string.
   *
   * @param integer $number Number of characters in length to append to the prefix.
   * @param string $prefix Prefix to use.
   * @return string Randomly generated string.
   */
  function randomName($number = 4, $prefix = 'simpletest_') {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_';
    for ($x = 0; $x < $number; $x++) {
      $prefix .= $chars{mt_rand(0, strlen($chars)-1)};
      if ($x == 0) {
        $chars .= '0123456789';
      }
    }
    return $prefix;
  }

  /**
   * Enables a drupal module in the test database. Any module that is not
   * part of the required core modules needs to be enabled in order to use
   * it in a test.
   *
   * @param string $name Name of the module to enable.
   * @return boolean Success.
   */
  function drupalModuleEnable($name) {
    if (module_exists($name)) {
      $this->pass(" [module] $name already enabled");
      return TRUE;
    }
    $this->checkOriginalModules();
    if (array_search($name, $this->_modules) === FALSE) {
      $this->_modules[$name] = $name;
      $form_state['values'] = array('status' => $this->_modules, 'op' => t('Save configuration'));
      drupal_execute('system_modules', $form_state);

      //rebuilding all caches
      drupal_rebuild_theme_registry();
      node_types_rebuild();
      menu_rebuild();
      cache_clear_all('schema', 'cache');
      module_rebuild_cache();
    }
  }

  /**
   * Disables a drupal module in the test database.
   *
   * @param string $name Name of the module.
   * @return boolean Success.
   * @see drupalModuleEnable()
   */
  function drupalModuleDisable($name) {
    if (!module_exists($name)) {
      $this->pass(" [module] $name already disabled");
      return TRUE;
    }
    $this->checkOriginalModules();
    if (($key = array_search($name, $this->_modules)) !== FALSE) {
      unset($this->_modules[$key]);
      $form_state['values'] = array('status' => $this->_modules, 'op' => t('Save configuration'));
      drupal_execute('system_modules', $form_state);

      //rebuilding all caches
      drupal_rebuild_theme_registry();
      node_types_rebuild();
      menu_rebuild();
      cache_clear_all('schema', 'cache');
      module_rebuild_cache();
    }
  }

  /**
   * Retrieves and saves current modules list into $_originalModules and $_modules.
   */
  function checkOriginalModules() {
    if (empty($this->_originalModules)) {
      require_once ('./modules/system/system.admin.inc');
      $form_state = array();
      $form = drupal_retrieve_form('system_modules', $form_state);
      $this->_originalModules = drupal_map_assoc($form['status']['#default_value']);
      $this->_modules = $this->_originalModules;
    }
  }

  /**
   * Set a drupal variable in the test environment. Any variable settings that deviate
   * from the default need to be set in the test.
   *
   * @param string $name Name of the variable to set.
   * @param mixed $value Value to set.
   */
  function drupalVariableSet($name, $value) {
    /* NULL variables would anyways result in default because of isset */
    $old_value = variable_get($name, NULL);
    if ($value !== $old_value) {
      variable_set($name, $value);
      /* Use array_key_exists instead of isset so NULL values do not get overwritten */
      if (!array_key_exists($name, $this->_cleanupVariables)) {
        $this->_cleanupVariables[$name] = $old_value;
      }
    }
  }

  /**
   * Create a user with a given set of permissions. The permissions correspond to the
   * names given on the privileges page.
   *
   * @param array $permissions Array of permission names to assign to user.
   * @return A fully loaded user object with pass_raw property, or FALSE if account
   *   creation fails.
   */
  function drupalCreateUser($permissions = NULL) {
    // Create a role with the given permission set.
    $rid = $this->_drupalCreateRole($permissions);
    if (!$rid) {
      return FALSE;
    }

    // Create a user assigned to that role.
    $edit = array();
    $edit['name']   = $this->randomName();
    $edit['mail']   = $edit['name'] .'@example.com';
    $edit['roles']  = array($rid => $rid);
    $edit['pass']   = user_password();
    $edit['status'] = 1;

    $account = user_save('', $edit);

    $this->assertTrue(!empty($account->uid), " [user] name: $edit[name] pass: $edit[pass] created");
    if (empty($account->uid)) {
      return FALSE;
    }

    // Add to list of users to remove when testing is completed.
    $this->_cleanupUsers[] = $account->uid;

    // Add the raw password so that we can log in as this user.
    $account->pass_raw = $edit['pass'];
    return $account;
  }

  /**
   * Internal helper function; Create a role with specified permissions.
   *
   * @param array $permissions Array of permission names to assign to role.
   * @return integer Role ID of newly created role, or FALSE if role creation failed.
   */
  private function _drupalCreateRole($permissions = NULL) {
    // Generate string version of permissions list.
    if ($permissions === NULL) {
      $permission_string = 'access comments, access content, post comments, post comments without approval';
    } else {
      $permission_string = implode(', ', $permissions);
    }

    // Create new role.
    $role_name = $this->randomName();
    db_query("INSERT INTO {role} (name) VALUES ('%s')", $role_name);
    $role = db_fetch_object(db_query("SELECT * FROM {role} WHERE name = '%s'", $role_name));
    $this->assertTrue($role, " [role] created name: $role_name, id: " . (isset($role->rid) ? $role->rid : t('-n/a-')));
    if ($role && !empty($role->rid)) {
      // Assign permissions to role and mark it for clean-up.
      db_query("INSERT INTO {permission} (rid, perm) VALUES (%d, '%s')", $role->rid, $permission_string);
      $this->assertTrue(db_affected_rows(), ' [role] created permissions: ' . $permission_string);
      $this->_cleanupRoles[] = $role->rid;
      return $role->rid;
    } else {
      return FALSE;
    }
  }

  /**
   * Logs in a user with the internal browser. If already logged in then logs the current
   * user out before logging in the specified user. If no user is specified then a new
   * user will be created and logged in.
   *
   * @param object $user User object representing the user to login.
   * @return object User that was logged in. Useful if no user was passed in order
   *   to retreive the created user.
   */
  function drupalLogin($user = NULL) {
    if ($this->_logged_in) {
      $this->drupalLogout();
    }

    if (!isset($user)) {
      $user = $this->_drupalCreateRole();
    }

    $edit = array(
      'name' => $user->name,
      'pass' => $user->pass_raw
    );
    $this->drupalPost('user', $edit, t('Log in'));

    $pass = $this->assertText($user->name, ' [login] found name: '. $user->name);
    $pass = $pass && $this->assertNoText(t('The username %name has been blocked.', array('%name' => $user->name)), ' [login] not blocked');
    $pass = $pass && $this->assertNoText(t('The name %name is a reserved username.', array('%name' => $user->name)), ' [login] not reserved');

    $this->_logged_in = $pass;

    return $user;
  }

  /*
   * Logs a user out of the internal browser, then check the login page to confirm logout.
   */
  function drupalLogout() {
    // Make a request to the logout page.
    $this->drupalGet('logout');

    // Load the user page, the idea being if you were properly logged out you should be seeing a login screen.
    $this->drupalGet('user');
    $pass = $this->assertField('name', t('[logout] Username field found.'));
    $pass = $pass && $this->assertField('pass', t('[logout] Password field found.'));

    $this->_logged_in = !$pass;
  }

  /**
   * Generates a random database prefix and runs the install scripts on the prefixed database.
   * After installation many caches are flushed and the internal browser is setup so that the page
   * requests will run on the new prefix.
   */
  function setUp() {
    global $db_prefix, $simpletest_ua_key;
    if ($simpletest_ua_key) {
      $this->db_prefix_original = $db_prefix;
      $clean_url_original = variable_get('clean_url', 0);
      $db_prefix = 'simpletest'. mt_rand(1000, 1000000);
      include_once './includes/install.inc';
      drupal_install_system();
      $module_list = drupal_verify_profile('default', 'en');
      drupal_install_modules($module_list);
      $task = 'profile';
      default_profile_tasks($task, '');
      menu_rebuild();
      actions_synchronize();
      _drupal_flush_css_js();
      variable_set('install_profile', 'default');
      variable_set('install_task', 'profile-finished');
      variable_set('clean_url', $clean_url_original);
    }
    parent::setUp();
  }

  /**
   * Delete the tables created by setUp() and reset the database prefix.
   */
  function tearDown() {
    global $db_prefix;
    if (preg_match('/simpletest\d+/', $db_prefix)) {
      $schema = drupal_get_schema(NULL, TRUE);
      $ret = array();
      foreach ($schema as $name => $table) {
        db_drop_table($ret, $name);
      }
      $db_prefix = $this->db_prefix_original;
      $this->_logged_in = FALSE;
      $this->_modules = $this->_originalModules;
      $this->curlClose();
      return;
    }
    if ($this->_modules != $this->_originalModules) {
      $form_state['values'] = array('status' => $this->_originalModules, 'op' => t('Save configuration'));
      drupal_execute('system_modules', $form_state);

      //rebuilding all caches
      drupal_rebuild_theme_registry();
      node_types_rebuild();
      menu_rebuild();
      cache_clear_all('schema', 'cache');
      module_rebuild_cache();

      $this->_modules = $this->_originalModules;
    }

    foreach ($this->_cleanupVariables as $name => $value) {
      if (is_null($value)) {
        variable_del($name);
      } else {
        variable_set($name, $value);
      }
    }
    $this->_cleanupVariables = array();

    //delete nodes
    foreach ($this->_cleanupNodes as $nid) {
      node_delete($nid);
    }
    $this->_cleanupNodes = array();

    //delete roles
    while (sizeof($this->_cleanupRoles) > 0) {
      $rid = array_pop($this->_cleanupRoles);
      db_query("DELETE FROM {role} WHERE rid = %d",       $rid);
      db_query("DELETE FROM {permission} WHERE rid = %d", $rid);
    }

    //delete users and their content
    while (sizeof($this->_cleanupUsers) > 0) {
      $uid = array_pop($this->_cleanupUsers);
      // cleanup nodes this user created
      $result = db_query("SELECT nid FROM {node} WHERE uid = %d", $uid);
      while ($node = db_fetch_array($result)) {
        node_delete($node['nid']);
      }
      user_delete(array(), $uid);
    }

    //delete content types
    foreach ($this->_cleanupContentTypes as $type) {
      node_type_delete($type);
    }
    $this->_cleanupContentTypes = array();

    //Output drupal warnings and messages into assert messages
    $drupal_msgs = drupal_get_messages();
    foreach($drupal_msgs as $type => $msgs) {
      foreach ($msgs as $msg) {
        $this->assertTrue(TRUE, "$type: $msg");
      }
    }

    parent::tearDown();
  }

  /**
   * Set necessary reporter info.
   */
  function run(&$reporter) {
    $arr = array('class' => get_class($this));
    if (method_exists($this, 'getInfo')) {
      $arr = array_merge($arr, $this->getInfo());
    }
    $reporter->test_info_stack[] = $arr;
    parent::run($reporter);
    array_pop($reporter->test_info_stack);
  }

  /**
   * Initializes the cURL connection and gets a session cookie.
   *
   * This function will add authentaticon headers as specified in
   * simpletest_httpauth_username and simpletest_httpauth_pass variables.
   * Also, see the description of $curl_options among the properties.
   */
  protected function curlConnect() {
    global $base_url, $db_prefix, $simpletest_ua_key;
    if (!isset($this->ch)) {
      $this->ch = curl_init();
      $curl_options = $this->curl_options + array(
        CURLOPT_COOKIEJAR => $this->cookie_file,
        CURLOPT_URL => $base_url,
        CURLOPT_FOLLOWLOCATION => TRUE,
        CURLOPT_RETURNTRANSFER => TRUE,
      );
      if (preg_match('/simpletest\d+/', $db_prefix)) {
        $curl_options[CURLOPT_USERAGENT] = $db_prefix .','. $simpletest_ua_key;
      }
      if (!isset($curl_options[CURLOPT_USERPWD]) && ($auth = variable_get('simpletest_httpauth_username', ''))) {
        if ($pass = variable_get('simpletest_httpauth_pass', '')) {
          $auth .= ':'. $pass;
        }
        $curl_options[CURLOPT_USERPWD] = $auth;
      }
      return $this->curlExec($curl_options);
    }
  }

  /**
   * Peforms a cURL exec with the specified options after calling curlConnect().
   *
   * @param array $curl_options Custom cURL options.
   * @return string Content returned from the exec.
   */
  protected function curlExec($curl_options) {
    $this->curlConnect();
    $url = empty($curl_options[CURLOPT_URL]) ? curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL) : $curl_options[CURLOPT_URL];
    curl_setopt_array($this->ch, $this->curl_options + $curl_options);
    $this->_content = curl_exec($this->ch);
    $this->plain_text = FALSE;
    $this->elements = FALSE;
    $this->assertTrue($this->_content, t(' [browser] !method to !url, response is !length bytes.', array('!method' => isset($curl_options[CURLOPT_POSTFIELDS]) ? 'POST' : 'GET', '!url' => $url, '!length' => strlen($this->_content))));
    return $this->_content;
  }

  /**
   * Close the cURL handler and unset the handler.
   */
  protected function curlClose() {
    if (isset($this->ch)) {
      curl_close($this->ch);
      unset($this->ch);
    }
  }

  /**
   * Parse content returned from curlExec using DOM and simplexml.
   *
   * @return SimpleXMLElement A SimpleXMLElement or FALSE on failure.
   */
  protected function parse() {
    if (!$this->elements) {
      // DOM can load HTML soup. But, HTML soup can throw warnings, supress
      // them.
      @$htmlDom = DOMDocument::loadHTML($this->_content);
      if ($htmlDom) {
        $this->assertTrue(TRUE, t(' [browser] Valid HTML found on "@path"', array('@path' => $this->getUrl())));
        // It's much easier to work with simplexml than DOM, luckily enough
        // we can just simply import our DOM tree.
        $this->elements = simplexml_import_dom($htmlDom);
      }
    }
    return $this->elements;
  }

  /**
   * Retrieves a Drupal path or an absolute path.
   *
   * @param $path string Drupal path or url to load into internal browser
   * @param array $options Options to be forwarded to url().
   * @return The retrieved HTML string, also available as $this->drupalGetContent()
   */
  function drupalGet($path, $options = array()) {
    $options['absolute'] = TRUE;
    return $this->curlExec(array(CURLOPT_URL => url($path, $options)));
  }

  /**
   * Do a post request on a drupal page.
   * It will be done as usual post request with SimpleBrowser
   * By $reporting you specify if this request does assertions or not
   * Warning: empty ("") returns will cause fails with $reporting
   *
   * @param string  $path
   *   Location of the post form. Either a Drupal path or an absolute path or
   *   NULL to post to the current page.
   * @param array $edit
   *   Field data in an assocative array. Changes the current input fields
   *   (where possible) to the values indicated. A checkbox can be set to
   *   TRUE to be checked and FALSE to be unchecked.
   * @param string $submit
   *   Untranslated value, id or name of the submit button.
   * @param $tamper
   *   If this is set to TRUE then you can post anything, otherwise hidden and
   *   nonexistent fields are not posted.
   */
  function drupalPost($path, $edit, $submit, $tamper = FALSE) {
    $submit_matches = FALSE;
    if (isset($path)) {
      $html = $this->drupalGet($path);
    }
    if ($this->parse()) {
      $edit_save = $edit;
      // Let's iterate over all the forms.
      $forms = $this->elements->xpath('//form');
      foreach ($forms as $form) {
        if ($tamper) {
          // @TODO: this will be Drupal specific. One needs to add the build_id
          // and the token to $edit then $post that.
        }
        else {
          // We try to set the fields of this form as specified in $edit.
          $edit = $edit_save;
          $post = array();
          $submit_matches = $this->handleForm($post, $edit, $submit, $form);
          $action = isset($form['action']) ? $this->getAbsoluteUrl($form['action']) : $this->getUrl();
        }
        // We post only if we managed to handle every field in edit and the
        // submit button matches;
        if (!$edit && $submit_matches) {
          $encoded_post = '';
          foreach ($post as $key => $value) {
            if (is_array($value)) {
              foreach ($value as $v) {
                $encoded_post .= $key .'='. rawurlencode($v) .'&';
              }
            }
            else {
              $encoded_post .= $key .'='. rawurlencode($value) .'&';
            }
          }
          return $this->curlExec(array(CURLOPT_URL => $action, CURLOPT_POSTFIELDS => $encoded_post));
        }
      }
      // We have not found a form which contained all fields of $edit.
      $this->fail(t('Found the requested form'));
      $this->assertTrue($submit_matches, t('Found the @submit button', array('@submit' => $submit)));
      foreach ($edit as $name => $value) {
        $this->fail(t('Failed to set field @name to @value', array('@name' => $name, '@value' => $value)));
      }
    }
  }

  /**
   * Handle form input related to drupalPost(). Ensure that the specified fields
   * exist and attempt to create POST data in the correct manor for the particular
   * field type.
   *
   * @param array $post Reference to array of post values.
   * @param array $edit Reference to array of edit values to be checked against the form.
   * @param string $submit Form submit button value.
   * @param array $form Array of form elements.
   * @return boolean Submit value matches a valid submit input in the form.
   */
  protected function handleForm(&$post, &$edit, $submit, $form) {
    // Retrieve the form elements.
    $elements = $form->xpath('.//input|.//textarea|.//select');
    $submit_matches = FALSE;
    foreach ($elements as $element) {
      // SimpleXML objects need string casting all the time.
      $name = (string)$element['name'];
      // This can either be the type of <input> or the name of the tag itself
      // for <select> or <textarea>.
      $type = isset($element['type']) ? (string)$element['type'] : $element->getName();
      $value = isset($element['value']) ? (string)$element['value'] : '';
      if (isset($edit[$name])) {
        switch ($type) {
          case 'text':
          case 'textarea':
          case 'password':
            $post[$name] = $edit[$name];
            unset($edit[$name]);
            break;
          case 'radio':
            if ($edit[$name] == $value) {
              $post[$name] = $edit[$name];
              unset($edit[$name]);
            }
            break;
          case 'checkbox':
            // To prevent checkbox from being checked.pass in a FALSE,
            // otherwise the checkbox will be set to its value regardless
            // of $edit.
            if ($edit[$name] === FALSE) {
              unset($edit[$name]);
              continue 2;
            }
            else {
              unset($edit[$name]);
              $post[$name] = $value;
            }
            break;
          case 'select':
            $new_value = $edit[$name];
            foreach ($element->option as $option) {
              if (is_array($new_value)) {
                $option_value= (string)$option['value'];
                if (in_array($option_value, $new_value)) {
                  $post[$name][] = $option_value;
                  unset($edit[$name]);
                }
              }
              elseif ($new_value == $option['value']) {
                $post[$name] = $new_value;
                unset($edit[$name]);
              }
            }
        }
      }
      if (($type == 'submit' || $type == 'image') && $submit == $value) {
        $post[$name] = $value;
        $submit_matches = TRUE;
      }
      if (!isset($post[$name])) {
        switch ($type) {
          case 'textarea':
            $post[$name] = (string)$element;
            break;
          case 'select':
            $single = empty($element['multiple']);
            foreach ($element->option as $key => $option) {
              // For single select, we load the first option, if there is a
              // selected option that will overwrite it later.
              if ($option['selected'] || (!$key && $single)) {
                if ($single) {
                  $post[$name] = (string)$option['value'];
                }
                else {
                  $post[$name][] = (string)$option['value'];
                }
              }
            }
            break;
          case 'radio':
          case 'checkbox':
            if (!isset($element['checked'])) {
              break;
            }
            // Deliberate no break.
          default:
            $post[$name] = $value;
        }
      }
    }
    return $submit_matches;
  }

  /**
   * Follows a link by name.
   *
   * Will click the first link found with this link text by default, or a
   * later one if an index is given. Match is case insensitive with
   * normalized space. The label is translated label. There is an assert
   * for successful click.
   * WARNING: Assertion fails on empty ("") output from the clicked link.
   *
   * @param string $label Text between the anchor tags.
   * @param integer $index Link position counting from zero.
   * @param boolean $reporting Assertions or not.
   * @return boolean/string Page on success.
   */
  function clickLink($label, $index = 0) {
    $url_before = $this->getUrl();
    $ret = FALSE;
    if ($this->parse()) {
      $urls = $this->elements->xpath('//a[text()="'. $label .'"]');
      if (isset($urls[$index])) {
        $url_target = $this->getAbsoluteUrl($urls[$index]['href']);
        $curl_options = array(CURLOPT_URL => $url_target);
        $ret = $this->curlExec($curl_options);
      }
      $this->assertTrue($ret, " [browser] clicked link $label ($url_target) from $url_before");
    }
    return $ret;
  }

  /**
   * Takes a path and returns an absolute path.
   *
   * @param @path
   *   The path, can be a Drupal path or a site-relative path. It might have a
   *   query, too. Can even be an absolute path which is just passed through.
   * @return
   *   An absolute path.
   */
  function getAbsoluteUrl($path) {
    $options = array('absolute' => TRUE);
    $parts = parse_url($path);
    // This is more crude than the menu_is_external but enough here.
    if (empty($parts['host'])) {
      $path = $parts['path'];
      $base_path = base_path();
      $n = strlen($base_path);
      if (substr($path, 0, $n) == $base_path) {
        $path = substr($path, $n);
      }
      if (isset($parts['query'])) {
        $options['query'] = $parts['query'];
      }
      $path = url($path, $options);
    }
    return $path;
  }

  /**
   * Get the current url from the cURL handler.
   *
   * @return string current url.
   */
  function getUrl() {
    return curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL);
  }

  /**
   * Gets the current raw HTML of requested page.
   */
  function drupalGetContent() {
    return $this->_content;
  }

  /**
   * Pass if the raw text IS found on the loaded page, fail otherwise. Raw text
   * refers to the raw HTML that the page generated.
   *
   * @param string $raw Raw string to look for.
   * @param string $message Message to display.
   * @return boolean TRUE on pass.
   */
  function assertWantedRaw($raw, $message = "%s") {
    return $this->assertFalse(strpos($this->_content, $raw) === FALSE, $message);
  }

  /**
   * Pass if the raw text is NOT found on the loaded page, fail otherwise. Raw text
   * refers to the raw HTML that the page generated.
   *
   * @param string $raw Raw string to look for.
   * @param string $message Message to display.
   * @return boolean TRUE on pass.
   */
  function assertNoUnwantedRaw($raw, $message = "%s") {
    return $this->assertTrue(strpos($this->_content, $raw) === FALSE, $message);
  }


  /**
   * Pass if the text IS found on the text version of the page. The text version
   * is the equivilent of what a user would see when viewing through a web browser.
   * In other words the HTML has been filtered out of the contents.
   *
   * @param string $raw Text string to look for.
   * @param string $message Message to display.
   * @return boolean TRUE on pass.
   */
  function assertText($text, $message) {
    return $this->assertTextHelper($text, $message, FALSE);
  }

  /**
   * Pass if the text IS found on the text version of the page. The text version
   * is the equivilent of what a user would see when viewing through a web browser.
   * In other words the HTML has been filtered out of the contents.
   *
   * @param string $raw Text string to look for.
   * @param string $message Message to display.
   * @return boolean TRUE on pass.
   */
  function assertWantedText($text, $message) {
    return $this->assertTextHelper($text, $message, FALSE);
  }

  /**
   * Pass if the text is NOT found on the text version of the page. The text version
   * is the equivilent of what a user would see when viewing through a web browser.
   * In other words the HTML has been filtered out of the contents.
   *
   * @param string $raw Text string to look for.
   * @param string $message Message to display.
   * @return boolean TRUE on pass.
   */
  function assertNoText($text, $message) {
    return $this->assertTextHelper($text, $message, TRUE);
  }

  /**
   * Pass if the text is NOT found on the text version of the page. The text version
   * is the equivilent of what a user would see when viewing through a web browser.
   * In other words the HTML has been filtered out of the contents.
   *
   * @param string $raw Text string to look for.
   * @param string $message Message to display.
   * @return boolean TRUE on pass.
   */
  function assertNoUnwantedText($text, $message) {
    return $this->assertTextHelper($text, $message, TRUE);
  }

  /**
   * Filter out the HTML of the page and assert that the plain text us found. Called by
   * the plain text assertions.
   *
   * @param string $text Text to look for.
   * @param string $message Message to display.
   * @param boolean $not_exists The assert to make in relation to the text's existance.
   * @return boolean Assertion result.
   */
  protected function assertTextHelper($text, $message, $not_exists) {
    if ($this->plain_text === FALSE) {
      $this->plain_text = filter_xss($this->_content, array());
    }
    return $this->assertTrue($not_exists == (strpos($this->plain_text, $text) === FALSE), $message);
  }

  /**
   * Will trigger a pass if the Perl regex pattern is found in the raw content.
   *
   * @param string $pattern Perl regex to look for including the regex delimiters.
   * @param string $message Message to display.
   * @return boolean True if pass.
   */
  function assertPattern($pattern, $message = '%s') {
    return $this->assert(new PatternExpectation($pattern), $this->drupalGetContent(), $message);
  }

  /**
   * Will trigger a pass if the perl regex pattern is not present in raw content.
   *
   * @param string $pattern Perl regex to look for including the regex delimiters.
   * @param string $message Message to display.
   * @return boolean True if pass.
   */
  function assertNoPattern($pattern, $message = '%s') {
    return $this->assert(new NoPatternExpectation($pattern), $this->drupalGetContent(), $message);
  }

  /**
   * Pass if the page title is the given string.
   *
   * @param $title Text string to look for.
   * @param $message Message to display.
   * @return boolean TRUE on pass.
   */
  function assertTitle($title, $message) {
    return $this->assertTrue($this->parse() && $this->elements->xpath('//title[text()="'. $title .'"]'), $message);
  }

  /**
   * Assert that a field exists in the current page by the given XPath.
   *
   * @param string $xpath XPath used to find the field.
   * @param string $value Value of the field to assert.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertFieldByXPath($xpath, $value, $message) {
    $fields = array();
    if ($this->parse()) {
      $fields = $this->elements->xpath($xpath);
    }

    // If value specified then check array for match.
    $found = TRUE;
    if ($value) {
      $found = FALSE;
      foreach ($fields as $field) {
        if ($field['value'] == $value) {
          $found = TRUE;
        }
      }
    }
    return $this->assertTrue($fields && $found, $message);
  }

  /**
   * Assert that a field does not exists in the current page by the given XPath.
   *
   * @param string $xpath XPath used to find the field.
   * @param string $value Value of the field to assert.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertNoFieldByXPath($xpath, $value, $message) {
    $fields = array();
    if ($this->parse()) {
      $fields = $this->elements->xpath($xpath);
    }

    // If value specified then check array for match.
    $found = TRUE;
    if ($value) {
      $found = FALSE;
      foreach ($fields as $field) {
        if ($field['value'] == $value) {
          $found = TRUE;
        }
      }
    }
    return $this->assertFalse($fields && $found, $message);
  }

  /**
   * Assert that a field exists in the current page with the given name and value.
   *
   * @param string $name Name of field to assert.
   * @param string $value Value of the field to assert.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertFieldByName($name, $value = '', $message = '') {
    return $this->assertFieldByXPath($this->_constructFieldXpath('name', $name), $value, $message ? $message : t(' [browser] found field by name @name', array('@name' => $name)));
  }

  /**
   * Assert that a field does not exists in the current page with the given name and value.
   *
   * @param string $name Name of field to assert.
   * @param string $value Value of the field to assert.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertNoFieldByName($name, $value = '', $message = '') {
    return $this->assertNoFieldByXPath($this->_constructFieldXpath('name', $name), $value, $message ? $message : t(' [browser] did not find field by name @name', array('@name' => $name)));
  }

  /**
   * Assert that a field exists in the current page with the given id and value.
   *
   * @param string $id Id of field to assert.
   * @param string $value Value of the field to assert.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertFieldById($id, $value = '', $message = '') {
    return $this->assertFieldByXPath($this->_constructFieldXpath('id', $id), $value, $message ? $message : t(' [browser] found field by id @id', array('@id' => $id)));
  }

  /**
   * Assert that a field does not exists in the current page with the given id and value.
   *
   * @param string $id Id of field to assert.
   * @param string $value Value of the field to assert.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertNoFieldById($id, $value = '', $message = '') {
    return $this->assertNoFieldByXPath($this->_constructFieldXpath('id', $id), $value, $message ? $message : t(' [browser] did not find field by id @id', array('@id' => $id)));
  }

  /**
   * Assert that a field exists in the current page with the given name or id.
   *
   * @param string $field Name or id of the field.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertField($field, $message = '') {
    return $this->assertFieldByXPath($this->_constructFieldXpath('name', $field) .'|'. $this->_constructFieldXpath('id', $field), '', $message);
  }

  /**
   * Assert that a field does not exists in the current page with the given name or id.
   *
   * @param string $field Name or id of the field.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertNoField($field, $message = '') {
    return $this->assertNoFieldByXPath($this->_constructFieldXpath('name', $field) .'|'. $this->_constructFieldXpath('id', $field), '', $message);
  }

  /**
   * Construct an XPath for the given set of attributes and value.
   *
   * @param array $attribute Field attributes.
   * @param string $value Value of field.
   * @return string XPath for specified values.
   */
  function _constructFieldXpath($attribute, $value) {
    return '//textarea[@'. $attribute .'="'. $value .'"]|//input[@'. $attribute .'="'. $value .'"]|//select[@'. $attribute .'="'. $value .'"]';
  }

  /**
   * Assert the page responds with the specified response code.
   *
   * @param integer $code Reponse code. For example 200 is a successful page request. For
   *   a list of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param string $message Message to display.
   * @return boolean Assertion result.
   */
  function assertResponse($code, $message = '') {
    $curl_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    return $this->assertTrue($curl_code == $code, $message ? $message : t(' [browser] HTTP response expected !code, actual !curl_code', array('!code' => $code, '!curl_code' => $curl_code)));
  }
}
