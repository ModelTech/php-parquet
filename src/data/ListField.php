<?php
namespace codename\parquet\data;

use Exception;

use codename\parquet\helper\OtherExtensions;

class ListField extends Field
{
  /**
   * [ContainerName description]
   * @var string
   */
  const ContainerName = 'list';

  /**
   * [public description]
   * @var Field
   */
  public $item;

  /**
   * Whether the field itself can be null
   * @var bool
   */
  public $hasNulls;

  /**
   * @inheritDoc
   */
  public function __construct(string $name, ?Field $item = null, bool $nullable = false)
  {
    parent::__construct($name, SchemaType::List);
    $this->hasNulls = $nullable;
    $this->item = $item;
    $this->setPathPrefix(null);
    // $this->pathPrefix = null;
  }

  /**
   * @inheritDoc
   */
  public function setPathPrefix($value)
  {
    $this->setPath(OtherExtensions::AddPath($value, [ $this->name, static::ContainerName ]));
    // item might be null
    if($this->item) {
      $this->item->setPathPrefix($this->path);
    }
  }

  /**
   * [createWithNoItem description]
   * @param  string    $name [description]
   * @return ListField       [description]
   */
  public static function createWithNoItem(string $name) : ListField {
    return new ListField($name);
  }

  /**
   * @inheritDoc
   */
  public function PropagateLevels(
    int $parentRepetitionLevel,
    int $parentDefinitionLevel
  ): void {
    $rl = $parentRepetitionLevel;
    $dl = $parentDefinitionLevel;

    //"container" is optional, therefore +1 to DL
    $dl += 1;

    //"list" is repeated, both get +1
    $rl += 1;
    $dl += 1;

    $this->maxRepetitionLevel = $rl;
    $this->maxDefinitionLevel = $dl;

    //push to child item
    $this->item->PropagateLevels($rl, $dl);
  }

  /**
   * @inheritDoc
   */
  public function assign(\codename\parquet\data\Field $field): void
  {
    if($this->item !== null) {
      throw new Exception("item was already assigned to this list ({$this->name}), something is terribly wrong because a list can only have one item.");
    }

    $this->item = $field;
  }

  /**
   * @inheritDoc
   */
  public function Equals($other): bool
  {
    if ($other === null) return false;
    if ($other === $this) return true;
    if (get_class($other) != get_class($this)) return false;


    if($other instanceof ListField) {
      return $this->name == $other->name && $this->item->Equals($other->item);
    } else {
      throw new Exception('Invalid equality comparison');
    }
  }
}
