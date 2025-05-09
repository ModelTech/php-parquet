<?php
namespace codename\parquet;

class ParquetOptions {
  /**
   * [public description]
   * @var bool
   */
  public $TreatByteArrayAsString = false;

  /**
   * [public description]
   * @var bool
   */
  public $TreatBigIntegersAsDates = true;

  /**
   * Whether to calculate Statistics
   * (distinct_count, min_value, max_value)
   * for each data chunk
   * @var bool
   */
  public $CalculateStatistics = false;

  /**
   * Whether to write DataPages using V2 header
   * @var bool
   */
  public $WriteDataPageV2 = false;
}
