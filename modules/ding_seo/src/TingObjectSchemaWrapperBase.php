<?php
/**
 * @file
 * Defines \DingSEO\TingObjectSchemaWrapperBase.
 *
 * A base class with common functionality across search providers that can be
 * extended by provider specific implementations of schema wrappers.
 */

namespace DingSEO;

use Ting\TingObjectInterface;

abstract class TingObjectSchemaWrapperBase implements TingObjectSchemaWrapperInterface {
  /**
   * @var \Ting\TingObjectInterface.
   *   The wrapped ting object.
   */
  protected $ting_object;

  /**
   * @var string
   *   The URL to the cover image of the material (static cache).
   */
  protected $image_url;

  /**
   * @var bool|null
   *   Whether the work example has borrow action.
   */
  protected $has_borrow_action;

  /**
   * TingObjectSchemaWrapperBase constructor.
   */
  public function __construct(TingObjectInterface $ting_object, $has_borrow_action = NULL) {
    $this->ting_object = $ting_object;
    $this->has_borrow_action = $has_borrow_action;
  }

  /**
   * {@inheritdoc}
   */
  public function getCollectionURL() {
    $ting_collection = ting_collection_load($this->ting_object->getId());
    $collection_path = entity_uri('ting_collection', $ting_collection)['path'];
    return url($collection_path, [
      'absolute' => TRUE,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getObjectURL() {
    $object_path = entity_uri('ting_object', $this->ting_object)['path'];
    return url($object_path, [
      'absolute' => TRUE,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getImageURL() {
    if (isset($this->image_url)) {
      return $this->image_url;
    }
    $this->image_url = FALSE;

    $ting_object_id = $this->ting_object->getId();

    // First check if this is a known negative.
    if (cache_get('ting_covers:' . $ting_object_id)) {
      return $this->image_url;
    }

    $image_path = ting_covers_object_path($ting_object_id);
    // If the file already exists we can avoid asking cover providers. Note that
    // we only ask providers if it exists, and don't initiate any downloads.
    if (file_exists($image_path) || !empty(module_invoke_all('ting_covers', [$this->ting_object]))) {
      $this->image_url = file_create_url($image_path);
    }

    return $this->image_url;
  }

  /**
   * {@inheritdoc}
   */
  public function getImageDimensions() {
    $image_path = ting_covers_object_path($this->ting_object->getId());
    if (file_exists($image_path) && $size = getimagesize(drupal_realpath($image_path))) {
      return array_slice($size, 0, 2);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkExamples() {
    $work_examples = [];

    $collection = ting_collection_load($this->ting_object->getId());
    /** @var \TingEntity[] $ting_entities */
    $ting_entities = $collection->getEntities();

    // Instead of deferring reservability check, use the opportunity now to
    // check reservability for all work examples at once.
    $localIds = array_map(function ($ting_entity) {
      return $ting_entity->localId;
    }, $ting_entities);
    $reservability = ding_provider_invoke('reservation', 'is_reservable', $localIds);

    foreach ($ting_entities as $ting_entity) {
      $work_examples[] = new static($ting_entity->getTingObject(), $reservability[$ting_entity->localId]);
    }

    return $work_examples;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->ting_object->getTitle();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->ting_object->getAbstract();
  }

  /**
   * {@inheritdoc}
   */
  public function getBookEdition() {
    return reset($this->ting_object->getVersion());
  }

  /**
   * {@inheritdoc}
   */
  public function getDatePublished() {
    return $this->ting_object->getYear();
  }

  /**
   * {@inheritdoc}
   */
  public function getISBN() {
    $isbn_list = $this->ting_object->getIsbn();

    // Prefer 13 digit ISBN-13 nunbers.
    $isbn13_list = array_filter($isbn_list, function ($isbn) {
      $isbn_cmp = str_replace([' ', '-'], '', $isbn);
      if (strlen($isbn_cmp) === 13) {
        return $isbn;
      }
    });

    if (!empty($isbn13_list)) {
      return reset($isbn13_list);
    }
    return reset($isbn_list);
  }

  /**
   * {@inheritdoc}
   */
  public function hasBorrowAction() {
    if (!isset($this->has_borrow_action)) {
      $local_id = $this->ting_object->getSourceId();
      $reservability = ding_provider_invoke('reservation', 'is_reservable', [$local_id]);
      $this->has_borrow_action = $reservability[$local_id];
    }
    return $this->has_borrow_action;
  }

  /**
   * {@inheritdoc}
   */
  public function getLenderLibraryId() {
    $lender_library_id = variable_get('ding_seo_lender_library', NULL);
    if (!isset($lender_library_id)) {
      // Fallback to picking first library. This should be the correct in most
      // cases since it will be the first created.
      $library_nodes = ding_seo_get_library_nodes();
      $lender_library_id = reset(array_keys($library_nodes));
    }

    return url("node/$lender_library_id", ['absolute' => TRUE]);
  }
}
