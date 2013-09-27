<?php

/**
 * @file
 * Definition of Drupal\comment\CommentFormController.
 */

namespace Drupal\comment;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFormControllerNG;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\Language\Language;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\FieldInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for controller for comment forms.
 */
class CommentFormController extends EntityFormControllerNG {

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManager
   */
  protected $entityManager;

  /**
   * The field info service.
   *
   * @var \Drupal\field\FieldInfo
   */
  protected $fieldInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('field.info'),
      $container->get('current_user')
    );
  }

  /**
   * Constructs a new CommentRenderController.
   *
   * @param \Drupal\Core\Entity\EntityManager $entity_manager
   *   The entity manager service.
   * @param \Drupal\field\FieldInfo $field_info
   *   The field info service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */

  public function __construct(EntityManager $entity_manager, FieldInfo $field_info, AccountInterface $current_user) {
    $this->entityManager = $entity_manager;
    $this->fieldInfo = $field_info;
    $this->currentUser = $current_user;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::form().
   */
  public function form(array $form, array &$form_state) {
    $comment = $this->entity;
    $entity = $this->entityManager->getStorageController($comment->entity_type->value)->load($comment->entity_id->value);
    $field_name = $comment->field_name->value;
    $instance = $this->fieldInfo->getInstance($entity->entityType(), $entity->bundle(), $field_name);

    // Use #comment-form as unique jump target, regardless of entity type.
    $form['#id'] = drupal_html_id('comment_form');
    $form['#theme'] = array('comment_form__' . $entity->entityType() . '__' . $entity->bundle() . '__' . $field_name, 'comment_form');

    $anonymous_contact = $instance->getFieldSetting('anonymous');
    $is_admin = $comment->id() && $this->currentUser->hasPermission('administer comments');

    if (!$this->currentUser->isAuthenticated() && $anonymous_contact != COMMENT_ANONYMOUS_MAYNOT_CONTACT) {
      $form['#attached']['library'][] = array('system', 'jquery.cookie');
      $form['#attributes']['class'][] = 'user-info-from-cookie';
    }

    // If not replying to a comment, use our dedicated page callback for new
    // Comments on entities.
    if (!$comment->id() && empty($comment->pid->target_id)) {
      $form['#action'] = url('comment/reply/' . $entity->entityType() . '/' . $entity->id() . '/' . $field_name);
    }

    if (isset($form_state['comment_preview'])) {
      $form += $form_state['comment_preview'];
    }

    $form['author'] = array();
    // Display author information in a details element for comment moderators.
    if ($is_admin) {
      $form['author'] += array(
        '#type' => 'details',
        '#title' => $this->t('Administration'),
        '#collapsed' => TRUE,
      );
    }

    // Prepare default values for form elements.
    if ($is_admin) {
      $author = $comment->name->value;
      $status = (isset($comment->status->value) ? $comment->status->value : COMMENT_NOT_PUBLISHED);
      $date = (!empty($comment->date) ? $comment->date : DrupalDateTime::createFromTimestamp($comment->created->value));
      if (empty($form_state['comment_preview'])) {
        $form['#title'] = $this->t('Edit comment %title', array(
          '%title' => $comment->subject->value,
        ));
      }
    }
    else {
      if ($this->currentUser->isAuthenticated()) {
        $author = $this->currentUser->getUsername();
      }
      else {
        $author = ($comment->name->value ? $comment->name->value : '');
      }
      $status = ($this->currentUser->hasPermission('skip comment approval') ? COMMENT_PUBLISHED : COMMENT_NOT_PUBLISHED);
      $date = '';
    }

    // Add the author name field depending on the current user.
    $form['author']['name'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#default_value' => $author,
      '#required' => ($this->currentUser->isAnonymous() && $anonymous_contact == COMMENT_ANONYMOUS_MUST_CONTACT),
      '#maxlength' => 60,
      '#size' => 30,
    );
    if ($is_admin) {
      $form['author']['name']['#title'] = $this->t('Authored by');
      $form['author']['name']['#description'] = $this->t('Leave blank for %anonymous.', array('%anonymous' => $this->config('user.settings')->get('anonymous')));
      $form['author']['name']['#autocomplete_route_name'] = 'user.autocomplete';
    }
    elseif ($this->currentUser->isAuthenticated()) {
      $form['author']['name']['#type'] = 'item';
      $form['author']['name']['#value'] = $form['author']['name']['#default_value'];
      $username = array(
        '#theme' => 'username',
        '#account' => $this->currentUser,
      );
      $form['author']['name']['#markup'] = drupal_render($username);
    }

    // Add author e-mail and homepage fields depending on the current user.
    $form['author']['mail'] = array(
      '#type' => 'email',
      '#title' => $this->t('E-mail'),
      '#default_value' => $comment->mail->value,
      '#required' => ($this->currentUser->isAnonymous() && $anonymous_contact == COMMENT_ANONYMOUS_MUST_CONTACT),
      '#maxlength' => 64,
      '#size' => 30,
      '#description' => $this->t('The content of this field is kept private and will not be shown publicly.'),
      '#access' => $is_admin || ($this->currentUser->isAnonymous() && $anonymous_contact != COMMENT_ANONYMOUS_MAYNOT_CONTACT),
    );

    $form['author']['homepage'] = array(
      '#type' => 'url',
      '#title' => $this->t('Homepage'),
      '#default_value' => $comment->homepage->value,
      '#maxlength' => 255,
      '#size' => 30,
      '#access' => $is_admin || ($this->currentUser->isAnonymous() && $anonymous_contact != COMMENT_ANONYMOUS_MAYNOT_CONTACT),
    );

    // Add administrative comment publishing options.
    $form['author']['date'] = array(
      '#type' => 'datetime',
      '#title' => $this->t('Authored on'),
      '#default_value' => $date,
      '#size' => 20,
      '#access' => $is_admin,
    );

    $form['author']['status'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Status'),
      '#default_value' => $status,
      '#options' => array(
        COMMENT_PUBLISHED => $this->t('Published'),
        COMMENT_NOT_PUBLISHED => $this->t('Not published'),
      ),
      '#access' => $is_admin,
    );

    $form['subject'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#maxlength' => 64,
      '#default_value' => $comment->subject->value,
      '#access' => $instance->getFieldSetting('subject'),
    );

    // Used for conditional validation of author fields.
    $form['is_anonymous'] = array(
      '#type' => 'value',
      '#value' => ($comment->id() ? !$comment->uid->target_id : $this->currentUser->isAnonymous()),
    );

    // Make the comment inherit the current content language unless specifically
    // set.
    if ($comment->isNew()) {
      $language_content = language(Language::TYPE_CONTENT);
      $comment->langcode->value = $language_content->id;
    }

    // Add internal comment properties.
    $original = $comment->getUntranslated();
    foreach (array('cid', 'pid', 'entity_id', 'entity_type', 'field_id', 'uid', 'langcode') as $key) {
      $key_name = key($comment->$key->offsetGet(0)->getPropertyDefinitions());
      $form[$key] = array('#type' => 'value', '#value' => $original->$key->{$key_name});
    }

    return parent::form($form, $form_state, $comment);
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::actions().
   */
  protected function actions(array $form, array &$form_state) {
    $element = parent::actions($form, $form_state);
    $comment = $this->entity;
    $entity = $this->entityManager->getStorageController($comment->entity_type->value)->load($comment->entity_id->value);
    $instance = $this->fieldInfo->getInstance($comment->entity_type->value, $entity->bundle(), $comment->field_name->value);
    $preview_mode = $instance->getFieldSetting('preview');

    // No delete action on the comment form.
    unset($element['delete']);

    // Mark the submit action as the primary action, when it appears.
    $element['submit']['#button_type'] = 'primary';

    // Only show the save button if comment previews are optional or if we are
    // already previewing the submission.
    $element['submit']['#access'] = ($comment->id() && $this->currentUser->hasPermission('administer comments')) || $preview_mode != DRUPAL_REQUIRED || isset($form_state['comment_preview']);

    $element['preview'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#access' => $preview_mode != DRUPAL_DISABLED,
      '#validate' => array(
        array($this, 'validate'),
      ),
      '#submit' => array(
        array($this, 'submit'),
        array($this, 'preview'),
      ),
    );

    return $element;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::validate().
   */
  public function validate(array $form, array &$form_state) {
    parent::validate($form, $form_state);

    if (!empty($form_state['values']['cid'])) {
      // Verify the name in case it is being changed from being anonymous.
      $accounts = $this->entityManager->getStorageController('user')->loadByProperties(array('name' => $form_state['values']['name']));
      $account = reset($accounts);
      $form_state['values']['uid'] = $account ? $account->id() : 0;

      $date = $form_state['values']['date'];
      if ($date instanceOf DrupalDateTime && $date->hasErrors()) {
        form_set_error('date', $this->t('You have to specify a valid date.'));
      }
      if ($form_state['values']['name'] && !$form_state['values']['is_anonymous'] && !$account) {
        form_set_error('name', $this->t('You have to specify a valid author.'));
      }
    }
    elseif ($form_state['values']['is_anonymous']) {
      // Validate anonymous comment author fields (if given). If the (original)
      // author of this comment was an anonymous user, verify that no registered
      // user with this name exists.
      if ($form_state['values']['name']) {
        $accounts = $this->entityManager->getStorageController('user')->loadByProperties(array('name' => $form_state['values']['name']));
        if (!empty($accounts)) {
          form_set_error('name', $this->t('The name you used belongs to a registered user.'));
        }
      }
    }
  }

  /**
   * Overrides EntityFormController::buildEntity().
   */
  public function buildEntity(array $form, array &$form_state) {
    $comment = parent::buildEntity($form, $form_state);
    if (!empty($form_state['values']['date']) && $form_state['values']['date'] instanceOf DrupalDateTime) {
      $comment->created->value = $form_state['values']['date']->getTimestamp();
    }
    else {
      $comment->created->value = REQUEST_TIME;
    }
    $comment->changed->value = REQUEST_TIME;
    return $comment;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::submit().
   */
  public function submit(array $form, array &$form_state) {
    $comment = parent::submit($form, $form_state);

    // If the comment was posted by a registered user, assign the author's ID.
    // @todo Too fragile. Should be prepared and stored in comment_form()
    // already.
    if (!$comment->is_anonymous && !empty($comment->name->value) && ($account = user_load_by_name($comment->name->value))) {
      $comment->uid->target_id = $account->id();
    }
    // If the comment was posted by an anonymous user and no author name was
    // required, use "Anonymous" by default.
    if ($comment->is_anonymous && (!isset($comment->name->value) || $comment->name->value === '')) {
      $comment->name->value = $this->config('user.settings')->get('anonymous');
    }

    // Validate the comment's subject. If not specified, extract from comment
    // body.
    if (trim($comment->subject->value) == '') {
      // The body may be in any format, so:
      // 1) Filter it into HTML
      // 2) Strip out all HTML tags
      // 3) Convert entities back to plain-text.
      $comment_text = $comment->comment_body->processed;
      $comment->subject = Unicode::truncate(trim(String::decodeEntities(strip_tags($comment_text))), 29, TRUE);
      // Edge cases where the comment body is populated only by HTML tags will
      // require a default subject.
      if ($comment->subject->value == '') {
        $comment->subject->value = $this->t('(No subject)');
      }
    }

    return $comment;
  }

  /**
   * Form submission handler for the 'preview' action.
   *
   * @param $form
   *   An associative array containing the structure of the form.
   * @param $form_state
   *   A reference to a keyed array containing the current state of the form.
   */
  public function preview(array $form, array &$form_state) {
    $comment = $this->entity;
    drupal_set_title(t('Preview comment'), PASS_THROUGH);
    $form_state['comment_preview'] = comment_preview($comment);
    $form_state['rebuild'] = TRUE;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::save().
   */
  public function save(array $form, array &$form_state) {
    $entity = entity_load($form_state['values']['entity_type'], $form_state['values']['entity_id']);
    $comment = $this->entity;
    $field_name = $comment->field_name->value;
    $uri = $entity->uri();

    if ($this->currentUser->hasPermission('post comments') && ($this->currentUser->hasPermission('administer comments') || $entity->{$field_name}->status == COMMENT_OPEN)) {
      // Save the anonymous user information to a cookie for reuse.
      if ($this->currentUser->isAnonymous()) {
        user_cookie_save(array_intersect_key($form_state['values'], array_flip(array('name', 'mail', 'homepage'))));
      }

      $comment->save();
      $form_state['values']['cid'] = $comment->id();

      // Add an entry to the watchdog log.
      watchdog('content', 'Comment posted: %subject.', array('%subject' => $comment->subject->value), WATCHDOG_NOTICE, l(t('view'), 'comment/' . $comment->id(), array('fragment' => 'comment-' . $comment->id())));

      // Explain the approval queue if necessary.
      if ($comment->status->value == COMMENT_NOT_PUBLISHED) {
        if (!$this->currentUser->hasPermission('administer comments')) {
          drupal_set_message($this->t('Your comment has been queued for review by site administrators and will be published after approval.'));
        }
      }
      else {
        drupal_set_message($this->t('Your comment has been posted.'));
      }
      $query = array();
      // Find the current display page for this comment.
      $instance = $this->fieldInfo->getInstance($entity->entityType(), $entity->bundle(), $field_name);
      $page = comment_get_display_page($comment->id(), $instance);
      if ($page > 0) {
        $query['page'] = $page;
      }
      // Redirect to the newly posted comment.
      $redirect = array($uri['path'], array('query' => $query, 'fragment' => 'comment-' . $comment->id()) + $uri['options']);
    }
    else {
      watchdog('content', 'Comment: unauthorized comment submitted or comment submitted to a closed post %subject.', array('%subject' => $comment->subject->value), WATCHDOG_WARNING);
      drupal_set_message($this->t('Comment: unauthorized comment submitted or comment submitted to a closed post %subject.', array('%subject' => $comment->subject->value)), 'error');
      // Redirect the user to the entity they are commenting on.
      $redirect = $uri['path'];
    }
    $form_state['redirect'] = $redirect;
    // Clear the block and page caches so that anonymous users see the comment
    // they have posted.
    Cache::invalidateTags(array('content' => TRUE));
    $this->entityManager->getRenderController($entity->entityType())->resetCache(array($entity->id()));
  }
}
