<?php
namespace codename\parquet\file;

use codename\parquet\ParquetOptions;

use codename\parquet\adapter\BinaryReader;

use codename\parquet\data\DataType;
use codename\parquet\data\DataField;
use codename\parquet\data\DataColumn;
use codename\parquet\data\DataTypeFactory;
use codename\parquet\data\DataColumnStatistics;
use codename\parquet\data\DataTypeHandlerInterface;

use codename\parquet\format\Encoding;
use codename\parquet\format\PageType;
use codename\parquet\format\PageHeader;
use codename\parquet\format\ColumnChunk;
use codename\parquet\format\SchemaElement;

use codename\parquet\values\RunLengthBitPackingHybridValuesReader;

class DataColumnReader
{
  /**
   * [private description]
   * @var DataField
   */
  private $dataField;

  /**
   * [private description]
   * @var resource
   */
  private $inputStream;

  /**
   * [private description]
   * @var ColumnChunk
   */
  private $thriftColumnChunk;

  /**
   * [private description]
   * @var SchemaElement
   */
  private $thriftSchemaElement;

  /**
   * [private description]
   * @var ThriftFooter
   */
  private $footer;

  /**
   * [private description]
   * @var ParquetOptions
   */
  private $parquetOptions;

  /**
   * [private description]
   * @var ThriftStream
   */
  private $thriftStream;

  /**
   * [private description]
   * @var int
   */
  private $maxRepetitionLevel;

  /**
   * [private description]
   * @var int
   */
  private $maxDefinitionLevel;

  // private IDataTypeHandler _dataTypeHandler;

  /**
   * @param DataField      $dataField
   * @param resource       $inputStream
   * @param ColumnChunk    $thriftColumnChunk
   * @param ThriftFooter   $footer
   * @param ParquetOptions $parquetOptions
   */
  public function __construct(
    DataField $dataField,
    $inputStream,
    ColumnChunk $thriftColumnChunk,
    ThriftFooter $footer,
    ParquetOptions $parquetOptions
  ) {
    $this->dataField = $dataField;
    $this->inputStream = $inputStream;
    $this->thriftColumnChunk = $thriftColumnChunk;
    $this->footer = $footer;
    $this->parquetOptions = $parquetOptions;

    $this->thriftStream = new ThriftStream($inputStream);
    $mrl = 0;
    $mdl = 0;
    // _footer.GetLevels(_thriftColumnChunk, out int mrl, out int mdl);
    $this->footer->getLevels($this->thriftColumnChunk, $mrl, $mdl);
    //      _dataField.MaxRepetitionLevel = mrl;
    //      _dataField.MaxDefinitionLevel = mdl;
    //      _maxRepetitionLevel = mrl;
    //      _maxDefinitionLevel = mdl;
    $this->dataField->maxRepetitionLevel = $mrl;
    $this->dataField->maxDefinitionLevel = $mdl;
    $this->maxRepetitionLevel = $mrl;
    $this->maxDefinitionLevel = $mdl;


    //      _thriftSchemaElement = _footer.GetSchemaElement(_thriftColumnChunk);
    $this->thriftSchemaElement = $this->footer->getSchemaElement($this->thriftColumnChunk);

    //      _dataTypeHandler = DataTypeFactory.Match(_thriftSchemaElement, _parquetOptions);

    $this->dataTypeHandler = DataTypeFactory::matchSchemaElement($this->thriftSchemaElement, $parquetOptions);
  }

  /**
   * [protected description]
   * @var DataTypeHandlerInterface
   */
  protected $dataTypeHandler;

  /**
   * [getFileOffset description]
   * @return int [description]
   */
  protected function getFileOffset() : int {
    return min(array_filter([
      $this->thriftColumnChunk->meta_data->dictionary_page_offset,
      $this->thriftColumnChunk->meta_data->data_page_offset
    ], function($v) { return $v != 0; }));
  }

  /**
   * [getThriftColumnChunk description]
   * @return \codename\parquet\format\ColumnChunk [description]
   */
  public function getThriftColumnChunk(): \codename\parquet\format\ColumnChunk {
    return $this->thriftColumnChunk;
  }

  /**
   * [readDataPage description]
   * @param PageHeader    $ph        [description]
   * @param ColumnRawData $cd        [description]
   * @param int           $maxValues [description]
   */
  protected function readDataPage(PageHeader $ph, ColumnRawData $cd, int $maxValues) : void {

    $bytes = $this->readPageDataByPageHeader($ph);

    $reader = \codename\parquet\adapter\BinaryReader::createInstance($bytes);

    if($this->maxRepetitionLevel > 0) {
      //todo: use rented buffers, but be aware that rented length can be more than requested so underlying logic relying on array length must be fixed too.
      if($cd->repetitions === null) { // TODO/QUESTION: may they even be null?
        $cd->repetitions = array_fill(0, $cd->maxCount, null);
      }

      $cd->repetitionsOffset += $this->readLevels($reader, $this->maxRepetitionLevel, $cd->repetitions, $cd->repetitionsOffset, $ph->data_page_header->num_values);
    }

    if($this->maxDefinitionLevel > 0) {
      if($cd->definitions === null) {
        $cd->definitions = array_fill(0, $cd->maxCount, null);
      }

      $cd->definitionsOffset += $this->readLevels($reader, $this->maxDefinitionLevel, $cd->definitions, $cd->definitionsOffset, $ph->data_page_header->num_values);
    }

    if($ph->data_page_header === null) {
      throw new \Exception('file corrupt, data page header missing');
    }

    //
    // NOTE:
    // The Possible Fix from https://github.com/aloneguid/parquet-dotnet/commit/a8bfeef068ed7b29bad8a4150ed8e50362fb1720
    // is not suitable for this case, as it assumes the existence of Statistics (which are, in fact, optional).
    //
    // Original comment:
    //
    //    if statistics are defined, use null count to determine the exact number of items we should read
    //    however, I don't know if all parquet files with null values have stats defined. Maybe a better solution would
    //    be using a count of defined values (from reading definitions?)
    //
    // This is in fact based on a wrong assumption.
    // In the following passage, we're determining null count by checking definition levels.
    //

    $nullCount = $ph->data_page_header->statistics->null_count ?? null;

    //
    // Statistics' null_count is an optional field
    // if we have no data (null), we have to make sure
    // to determine the count of null values in the current data page
    //
    if($nullCount === null && $cd->definitions !== null) {
      //
      // We're counting all maxDefinitionLevel entries (non-nulls)
      // and subtract them from our known num_values (which include nulls)
      //
      // NOTE: we are only comparing the definition levels within current page bounds
      // $cd->definitionsOffset has already been incremented at this point, so we assume the initial state
      // start index: definitionsOffset - num_values
      // end index:   definitionsOffset
      //
      $definitionCount = 0;
      for ($i = $cd->definitionsOffset - $ph->data_page_header->num_values; $i < $cd->definitionsOffset; $i++) {
        if($cd->definitions[$i] === $this->maxDefinitionLevel) {
          $definitionCount++;
        }
      }

      $nullCount = $ph->data_page_header->num_values - $definitionCount;
    }

    $maxReadCount = $ph->data_page_header->num_values - (int)($nullCount ?? 0);
    $this->readColumn($reader, $ph->data_page_header->encoding, $maxValues, $maxReadCount, $cd);
  }

  /**
   * [readDataPageV2 description]
   * @param PageHeader    $ph        [description]
   * @param ColumnRawData $cd        [description]
   * @param int           $maxValues [description]
   */
  protected function readDataPageV2(PageHeader $ph, ColumnRawData $cd, int $maxValues) : void {

    $bytes = $this->readPageDataByPageHeader($ph);

    $reader = \codename\parquet\adapter\BinaryReader::createInstance($bytes);

    if($this->maxRepetitionLevel > 0) {
      //todo: use rented buffers, but be aware that rented length can be more than requested so underlying logic relying on array length must be fixed too.
      if($cd->repetitions === null) { // TODO/QUESTION: may they even be null?
        $cd->repetitions = array_fill(0, $cd->maxCount, null);
      }

      $cd->repetitionsOffset += $this->readLevelsV2($reader, $ph->data_page_header_v2->repetition_levels_byte_length, $this->maxRepetitionLevel, $cd->repetitions, $cd->repetitionsOffset, $ph->data_page_header_v2->num_values);
    }

    if($this->maxDefinitionLevel > 0) {
      if($cd->definitions === null) {
        $cd->definitions = array_fill(0, $cd->maxCount, null);
      }

      $cd->definitionsOffset += $this->readLevelsV2($reader, $ph->data_page_header_v2->definition_levels_byte_length, $this->maxDefinitionLevel, $cd->definitions, $cd->definitionsOffset, $ph->data_page_header_v2->num_values);
    }

    if($ph->data_page_header_v2 === null) {
      throw new \Exception('file corrupt, data page header missing');
    }

    $nullCount = $ph->data_page_header_v2->num_nulls ?? null;

    if($nullCount === null && $cd->definitions !== null) {
      //
      // We're counting all maxDefinitionLevel entries (non-nulls)
      // and subtract them from our known num_values (which include nulls)
      //
      // NOTE: we are only comparing the definition levels within current page bounds
      // $cd->definitionsOffset has already been incremented at this point, so we assume the initial state
      // start index: definitionsOffset - num_values
      // end index:   definitionsOffset
      //
      $definitionCount = 0;

      for ($i = $cd->definitionsOffset - $ph->data_page_header_v2->num_values; $i < $cd->definitionsOffset; $i++) {
        if($cd->definitions[$i] === $this->maxDefinitionLevel) {
          $definitionCount++;
        }
      }
      $nullCount = $ph->data_page_header_v2->num_values - $definitionCount;
    }

    $maxReadCount = $ph->data_page_header_v2->num_values - (int)($nullCount ?? 0);

    $this->readColumn($reader, $ph->data_page_header_v2->encoding, $maxValues, $maxReadCount, $cd);
  }

  /**
   * [readLevels description]
   * @param  BinaryReader $reader   [description]
   * @param  int          $maxLevel [description]
   * @param  array        &$dest    [description]
   * @param  int|null     $offset   [description]
   * @param  int|null     $pageSize [description]
   * @return int                    [description]
   */
  protected function readLevels(BinaryReader $reader, int $maxLevel, array &$dest, ?int $offset, ?int $pageSize) : int {
    $bitWidth = static::getBitWidth($maxLevel);
    return RunLengthBitPackingHybridValuesReader::ReadRleBitpackedHybrid($reader, $bitWidth, 0, $dest, $offset, $pageSize);
  }

  /**
   * [readLevelsV2 description]
   * @param  BinaryReader $reader                 [description]
   * @param  int          $numBytes               [description]
   * @param  int          $maxLevel               [description]
   * @param  array        &$dest                   [description]
   * @param  int|null     $offset                 [description]
   * @param  int|null     $pageSize               [description]
   * @return int
   */
  protected function readLevelsV2(BinaryReader $reader, int $numBytes, int $maxLevel, array &$dest, ?int $offset, ?int $pageSize) : int {
    $bitWidth = static::getBitWidth($maxLevel);
    return RunLengthBitPackingHybridValuesReader::ReadRleBitpackedHybrid($reader, $bitWidth, $numBytes, $dest, $offset, $pageSize);
  }

  /**
   * [getBitWidth description]
   * @param  int $value [description]
   * @return int        [description]
   */
  public static function getBitWidth(int $value) : int
  {
     for ($i = 0; $i < 64; $i++)
     {
        if ($value === 0) return $i;
        $value >>= 1;
     }
     return 1;
  }

  protected function readColumn(BinaryReader $reader, int $encoding, int $totalValues, int $maxReadCount, ColumnRawData $cd)
  {
     //dictionary encoding uses RLE to encode data

     // print_r([
     //   'action' => 'readColumn',
     //   'dataFieldName' => $this->dataField->name,
     //   'totalValues' => $totalValues,
     // ]);

     if ($cd->values === null)
     {
        $cd->values = array_fill(0, $totalValues, null);
        // $cd->values = $this->dataTypeHandler->getArray((int)$totalValues, false, false);
     }

     if($cd->valuesOffset === null) {
       $cd->valuesOffset = 0;
     }

     switch ($encoding)
     {
        case Encoding::PLAIN:
           $cd->valuesOffset += $this->dataTypeHandler->read($reader, $this->thriftSchemaElement, $cd->values, $cd->valuesOffset);
           break;

        case Encoding::RLE:
          if ($cd->indexes === null) {
            // QUESTION: should we pre-fill the array?
            $cd->indexes = array_fill(0, $totalValues, 0);
          }
          $indexCount = RunLengthBitPackingHybridValuesReader::Read($reader, $this->thriftSchemaElement->type_length, $cd->indexes, 0, $maxReadCount);
          $this->dataTypeHandler->mergeDictionary($cd->dictionary, $cd->indexes, $cd->values, $cd->valuesOffset, $indexCount);
          $cd->valuesOffset += $indexCount;
          break;

        //
        // CHANGED 2021-10-15: for RLE_DICTIONARY, use the same as PLAIN_DICTIONARY
        // as we internally handle RLE
        //
        case Encoding::RLE_DICTIONARY:
        case Encoding::PLAIN_DICTIONARY:
        case Encoding::RLE_DICTIONARY:
          if($cd->indexes === null) {
            // QUESTION: should we pre-fill the array?
            $cd->indexes = array_fill(0, $totalValues, null);
          }
          $indexCount = static::readPlainDictionary($reader, $maxReadCount, $cd->indexes, 0);
          $this->dataTypeHandler->mergeDictionary($cd->dictionary, $cd->indexes, $cd->values, $cd->valuesOffset, $indexCount);

          $cd->valuesOffset += $indexCount;
          break;

        default:
           $encodingName = Encoding::$__names[$encoding]??'undefined';
           throw new \Exception("encoding {$encoding} ({$encodingName}) is not supported.");
     }
  }

  /**
   * [ReadPlainDictionary description]
   * @param  BinaryReader $reader       [description]
   * @param  int          $maxReadCount [description]
   * @param  int[]        &$dest         [description]
   * @param  int          $offset       [description]
   * @return int                        [description]
   */
  protected static function ReadPlainDictionary(BinaryReader $reader, int $maxReadCount, array &$dest, int $offset): int
  {
    $start = $offset;
    $bitWidth = ord($reader->readBytes(1)); // read byte?

    //
    //  parquet-dotnet PR #96 port
    //  Fix reading of plain dictionary with zero length
    //
    $length = static::GetRemainingLength($reader);

    //when bit width is zero reader must stop and just repeat zero maxValue number of times
    if ($bitWidth === 0 || $length === 0)
    {
      for ($i = 0; $i < $maxReadCount; $i++)
      {
        $dest[$offset++] = 0;
      }
    }
    else
    {
      //
      // parquet-dotnet PR #95 port
      // Empty page handling
      //
      if($length !== 0) {
        $offset += RunLengthBitPackingHybridValuesReader::ReadRleBitpackedHybrid($reader, $bitWidth, $length, $dest, $offset, $maxReadCount);
      }
    }

    return $offset - $start;
  }

  /**
   * [GetRemainingLength description]
   * @param  BinaryReader $reader [description]
   * @return int                  [description]
   */
  private static function GetRemainingLength(BinaryReader $reader): int
  {
    // return (int)(fstat($reader->getInputHandle())['size'] - ftell($reader->getInputHandle()));
    return (int)($reader->getEofPosition() - $reader->getPosition());
  }

  /**
   * Returns an iterable instance of a DataColumn
   * Which internally uses this reader
   * @return \codename\parquet\data\DataColumnIterable [description]
   */
  public function getDatacolumnIterable(): \codename\parquet\data\DataColumnIterable {
    return new \codename\parquet\data\DataColumnIterable($this->dataField, $this);
  }

  /**
   * Dictionary during single page read
   * As the dictionary data itself is only present in/below the *initial* header for this column
   * @var array|null
   */
  protected $cachedDictionary = null;

  /**
   * See ->cachedDictionary
   * @var int
   */
  protected $cachedDictionaryOffset = null;

  /**
   * Reads a single data page
   * @param  int        $currentFileOffset
   * @param  array      &$data
   * @param  array      &$definitionLevels
   * @param  array|null &$repetitionLevels
   * @return int
   */
  public function readOneDataPage(int $currentFileOffset, array &$data, array &$definitionLevels, ?array &$repetitionLevels): int {

    // Construct a ColumnRawData instance
    // as the DataColumnReader expects one to work with
    // though we discard this later and just use the data we read.
    $colData = new ColumnRawData();

    // originally, we'd set max value count of column (all data pages)
    // but we're only reading a single data page
    // $colData->maxCount = $this->thriftColumnChunk->meta_data->num_values;
    // So we'll only use the count of values
    // of the respective data page currently handled

    if($currentFileOffset === 0) {
      //
      // No data pages read
      // we assume $currentFileOffset to symbolize no existing current offset in DataColumn
      //
      $fileOffset = $this->getFileOffset();
      fseek($this->inputStream, $fileOffset, SEEK_SET);

      $ph = $this->thriftStream->Read(PageHeader::class);

      $dictionary = [];
      $dictionaryOffset = 0;
      if($this->TryReadDictionaryPage($ph, $dictionary, $dictionaryOffset)) {
        $ph = $this->thriftStream->Read(PageHeader::class);
      }

      // Set cached values for dictionary
      // as we can only retrieve them right here
      // and use them in followup reads
      $this->cachedDictionary = $dictionary;
      $this->cachedDictionaryOffset = $dictionaryOffset;

    } else {
      //
      // Continue reading at a specific stream position
      // We have to seek, as the stream might have been repositioned from outside
      // (e.g. other iterators, readers, manual intervention, etc.)
      //
      $fileOffset = $currentFileOffset;
      fseek($this->inputStream, $fileOffset, SEEK_SET);
      $ph = $this->thriftStream->Read(PageHeader::class);
    }

    $colData->dictionary = $this->cachedDictionary;
    $colData->dictionaryOffset = $this->cachedDictionaryOffset;

    if($ph->type !== PageType::DATA_PAGE && $ph->type !== PageType::DATA_PAGE_V2) {
      // Non-data page, stop right here
      // We do NOT return a valid file offset here
      return -1;
    }

    if($ph->type === PageType::DATA_PAGE) {
      $maxValues = $ph->data_page_header->num_values; // only number of entries from data page
      // We only want to read as many values as there are
      // in the current data page (not column chunk overall)
      $colData->maxCount = $maxValues;
      $this->readDataPage($ph, $colData, $maxValues);
    } else if($ph->type === PageType::DATA_PAGE_V2) {
      $maxValues = $ph->data_page_header_v2->num_values; // only number of entries from data page
      // We only want to read as many values as there are
      // in the current data page (not column chunk overall)
      $colData->maxCount = $maxValues;
      $this->readDataPageV2($ph, $colData, $maxValues);
    } else {
      // Unexpected data
      throw new \Exception('Unexpected non-datapage');
    }

    // Set the variables that have been passed by-ref
    $data = $colData->values;
    $definitionLevels = $colData->definitions;
    $repetitionLevels = $colData->repetitions;

    // return the latest position in the file stream
    return ftell($this->inputStream);
  }

  /**
   * Reads a whole column chunk's data (all data pages)
   * @return DataColumn
   */
  public function read() : DataColumn {
    $fileOffset = $this->getFileOffset();
    $maxValues = $this->thriftColumnChunk->meta_data->num_values;

    fseek($this->inputStream, $fileOffset, SEEK_SET);

    $colData = new ColumnRawData();
    $colData->maxCount = $this->thriftColumnChunk->meta_data->num_values;

    // Read the first PageHeader
    $ph = $this->thriftStream->Read(PageHeader::class);

    $dictionary = [];
    $dictionaryOffset = 0;

    // Try to read a Dictionary Page, if any.
    if($this->TryReadDictionaryPage($ph, $dictionary, $dictionaryOffset)) {
      $ph = $this->thriftStream->Read(PageHeader::class);
    }

    $colData->dictionary = $dictionary;
    $colData->dictionaryOffset = $dictionaryOffset;

    while(true) {

      if($ph->type === PageType::DATA_PAGE) {
        $this->readDataPage($ph, $colData, $maxValues);
      } else if($ph->type === PageType::DATA_PAGE_V2) {
        $this->readDataPageV2($ph, $colData, $maxValues);
      } else {
        // Unexpected data
        throw new \Exception('Unexpected non-datapage');
      }

      $totalCount = max(
        ($colData->values === null ? 0 : $colData->valuesOffset),
        ($colData->definitions === null ? 0 : $colData->definitionsOffset)
      );

      if($totalCount >= $maxValues) {
        break;
      }

      // Try to read one more Data page
      // And stop if it is something else.
      $ph = $this->thriftStream->Read(PageHeader::class);
      if($ph->type !== PageType::DATA_PAGE && $ph->type !== PageType::DATA_PAGE_V2) {
        break;
      }
    }

    // all the data is available here!

    // return new DataColumn(
    //   _dataField, colData.values,
    //   colData.definitions, _maxDefinitionLevel,
    //   colData.repetitions, _maxRepetitionLevel,
    //   colData.dictionary,
    //   colData.indexes);

    $finalColumn = DataColumn::DataColumnExtended(
      $this->dataField, $colData->values,
      $colData->definitions, $this->maxDefinitionLevel,
      $colData->repetitions, $this->maxRepetitionLevel,
      $colData->dictionary,
      $colData->indexes
    );

    if($this->thriftColumnChunk->meta_data->statistics) {
      $finalColumn->statistics = new DataColumnStatistics(
        $this->thriftColumnChunk->meta_data->statistics->null_count,
        $this->thriftColumnChunk->meta_data->statistics->distinct_count,
        $this->dataTypeHandler->plainDecode($this->thriftSchemaElement, $this->thriftColumnChunk->meta_data->statistics->min_value),
        $this->dataTypeHandler->plainDecode($this->thriftSchemaElement, $this->thriftColumnChunk->meta_data->statistics->max_value)
      );
    }

    return $finalColumn;
  }

  /**
   * [TryReadDictionaryPage description]
   * @param  PageHeader   $ph               [description]
   * @param  array|null   &$dictionary       [description]
   * @param  int|null     &$dictionaryOffset [description]
   * @return bool                         [description]
   */
  private function TryReadDictionaryPage(PageHeader $ph, ?array &$dictionary, ?int &$dictionaryOffset) : bool
  {
    if ($ph->type !== PageType::DICTIONARY_PAGE)
    {
      $dictionary = null;
      $dictionaryOffset = 0;
      return false;
    }

    //Dictionary page format: the entries in the dictionary - in dictionary order - using the plain encoding.


    // using (BytesOwner bytes = ReadPageData(ph))
    // {
    $bytes = $this->readPageDataByPageHeader($ph);
      //todo: this is ugly, but will be removed once other parts are migrated to System.Memory
      // using (var ms = new MemoryStream(bytes.Memory.ToArray()))
      // {
      // $ms = $bytes;

        // using (var dataReader = new BinaryReader(ms))
        // {
    $dataReader = \codename\parquet\adapter\BinaryReader::createInstance($bytes); // new \codename\parquet\adapter\PhpBinaryReader($ms);


        // dictionary = _dataTypeHandler.GetArray(ph.Dictionary_page_header.Num_values, false, false);


    // NOTE: we have to pre-fill the dictionary at this point.
    // Otherwise, some counts and iterators won't do their work correctly
    // We might even HAVE to implement this f*cking getArray method.
    $dictionary = array_fill(0, $ph->dictionary_page_header->num_values, null); // $this->dataTypeHandler->getArray($ph->dictionary_page_header->num_values, false, false)

        // dictionaryOffset = _dataTypeHandler.Read(dataReader, _thriftSchemaElement, dictionary, 0);
        // $dictionaryOffset = $this->dataTypeHandler->read($dataReader, $this->thriftSchemaElement, $dictionary, 0);

        // $this->thriftSchemaElement->

    $dictionaryOffset = $this->dataTypeHandler->read($dataReader, $this->thriftSchemaElement, $dictionary, 0);

        // for ($i=0; $i < $ph->dictionary_page_header->num_values; $i++) {
        //   // code...
        //
        //   // $dictionary[] = $this->thriftSchemaElement->read($dataReader);
        //   // print_r($ex);
        // }

    return true;
        // }
      // }
    // }
  }

  /**
   * Reads data according to page header
   * @param  PageHeader $pageHeader [description]
   * @return string                 [description]
   */
  protected function readPageDataByPageHeader(PageHeader $pageHeader) {
    if($pageHeader->type === PageType::DATA_PAGE_V2) {
      // The levels are not compressed in V2 format
      // https://github.com/mathworks/arrow/blob/c0e4d31ad5ed6e99df410be0f0f8d5521d32e66a/cpp/src/parquet/column_reader.cc:397
      $levelsByteLength = $pageHeader->data_page_header_v2->repetition_levels_byte_length + $pageHeader->data_page_header_v2->definition_levels_byte_length;

      // read levels data, as it is not compressed
      // input stream is advanced by $levelsLength
      if($levelsByteLength > 0) {
        $levelsData = fread($this->inputStream, $levelsByteLength);
      } else {
        $levelsData = null; // or empty string?
      }

      // prepend levels data we just read
      return $levelsData . DataStreamFactory::ReadPageData(
        $this->inputStream,
        $this->thriftColumnChunk->meta_data->codec,
        $pageHeader->compressed_page_size - $levelsByteLength, $pageHeader->uncompressed_page_size - $levelsByteLength
      );
    } else {
      return DataStreamFactory::ReadPageData(
        $this->inputStream,
        $this->thriftColumnChunk->meta_data->codec,
        $pageHeader->compressed_page_size, $pageHeader->uncompressed_page_size
      );
    }
  }

}

class ColumnRawData {
  /**
   * [public description]
   * @var int
   */
  public $maxCount = 0;

  /**
   * [public description]
   * @var int[]
   */
  public $repetitions;

  /**
   * [public description]
   * @var int
   */
  public $repetitionsOffset = 0;

  /**
   * [public description]
   * @var int[]
   */
  public $definitions;

  /**
   * [public description]
   * @var int
   */
  public $definitionsOffset = 0;

  /**
   * [public description]
   * @var int[]
   */
  public $indexes;

  /**
   * [public description]
   * @var array
   */
  public $values;

  /**
   * [public description]
   * @var int
   */
  public $valuesOffset = 0;

  /**
   * [public description]
   * @var array
   */
  public $dictionary;

  /**
   * [public description]
   * @var int
   */
  public $dictionaryOffset = 0;
}
