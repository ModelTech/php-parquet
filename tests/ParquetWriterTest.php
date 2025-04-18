<?php
declare(strict_types=1);
namespace codename\parquet\tests;

use Exception;

use codename\parquet\ParquetReader;
use codename\parquet\ParquetWriter;

use codename\parquet\data\Schema;
use codename\parquet\data\DataField;
use codename\parquet\data\DataColumn;

final class ParquetWriterTest extends TestBase
{
  /**
   * [testAppendWithDifferingSchemaFails description]
   */
  public function testAppendWithDifferingSchemaFails(): void {
    $schema = new Schema([
      $id = DataField::createFromType('id', 'integer'),
      $id2 = DataField::createFromType('id2', 'integer'),
    ]);
    $handle = fopen('php://memory', 'r+');
    $writer = new ParquetWriter($schema, $handle);
    $rg = $writer->createRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 1 ]));
    $rg->WriteColumn(new DataColumn($id2, [ 111 ]));
    $rg->finish();
    $writer->finish();

    $schema = new Schema([
      $id = DataField::createFromType('id', 'integer'),
      $id2string = DataField::createFromType('id2', 'string'),
    ]);

    $this->expectException(\codename\parquet\ParquetException::class);
    $this->expectExceptionMessage('passed schema does not match existing file schema, reason: [appending: id2] != [id2]');

    // Writer in append mode
    $writer = new ParquetWriter($schema, $handle, null, true);
  }


  /**
   * [testCannotWriteColumnsInWrongOrder description]
   */
  public function testCannotWriteColumnsInWrongOrder(): void
  {
    $schema = new Schema([
      DataField::createFromType('id', 'integer'),
      DataField::createFromType('id2', 'integer'),
    ]);

    $writer = new ParquetWriter($schema, fopen('php://memory', 'r+'));

    $gw = $writer->CreateRowGroup();

    // Expect an exception to be thrown
    $this->expectException(Exception::class);

    $gw->WriteColumn(new DataColumn($schema->getDataFields()[1], [ 1 ]));
  }

  /**
   * [testWrite_in_small_row_groups description]
   */
  public function testWrite_in_small_row_groups(): void
  {
    //write a single file having 3 row groups
    $id = DataField::createFromType('id', 'integer');
    $ms = fopen('php://memory', 'r+');

    $writer = new ParquetWriter(new Schema([$id]), $ms);

    $rg = $writer->createRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 1 ]));
    $rg->finish();

    $rg = $writer->createRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 2 ]));
    $rg->finish();

    $rg = $writer->createRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 3 ]));
    $rg->finish();

    $writer->finish();

    fseek($ms, 0);

    $reader = new ParquetReader($ms);

    $rg = $reader->OpenRowGroupReader(0);
    $this->assertEquals(1, $rg->getRowCount());
    $dc = $rg->ReadColumn($id);
    $this->assertEquals([1], $dc->getData());

    $rg = $reader->OpenRowGroupReader(1);
    $this->assertEquals(1, $rg->getRowCount());
    $dc = $rg->ReadColumn($id);
    $this->assertEquals([2], $dc->getData());

    $rg = $reader->OpenRowGroupReader(2);
    $this->assertEquals(1, $rg->getRowCount());
    $dc = $rg->ReadColumn($id);
    $this->assertEquals([3], $dc->getData());

    // Assert we have the correct num_rows value
    $this->assertEquals(3, $reader->getThriftMetadata()->num_rows);

    fclose($ms);
  }

  /**
   * [testAppendToFileReadsAllData description]
   */
  public function testAppendToFileReadsAllData(): void
  {
    //write a file with a single row group
    // var id = new DataField<int>("id");
    $id = DataField::createFromType("id", 'integer');
    $ms = fopen('php://memory', 'r+');

    $writer = new ParquetWriter(new Schema([$id]), $ms);
    $rg = $writer->CreateRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 1, 2 ]));
    $rg->finish();
    $writer->finish();
    // TODO: finish row group writer?
    // TODO: finish writer?

    // append to this file. Note that you cannot append to existing row group, therefore create a new one
    fseek($ms, 0);

    $writer = null;

    $writer = new ParquetWriter(new Schema([$id]), $ms, null, true);
    $rg = $writer->CreateRowGroup();

    $rg->WriteColumn(new DataColumn($id, [ 3, 4 ]));
    $rg->finish();
    $writer->finish();
    // TODO: finish row group writer?
    // TODO: finish writer?

    // check that this file now contains two row groups and all the data is valid
    fseek($ms, 0);

    $reader = new ParquetReader($ms);
    $this->assertEquals(2, $reader->getRowGroupCount());

    $rg = $reader->OpenRowGroupReader(0);
    $this->assertEquals(2, $rg->getRowCount());
    $this->assertEquals([ 1, 2 ], $rg->ReadColumn($id)->getData());

    $rg = $reader->OpenRowGroupReader(1);
    $this->assertEquals(2, $rg->getRowCount());
    $this->assertEquals([ 3, 4 ], $rg->ReadColumn($id)->getData());

  }

  /**
   * [public description]
   * @var array
   */
  public static $NullableColumnContentCases = [
    [ 1, 2 ],       // new object[] { new int?[] { 1, 2 } },
    [ null ],       // new object[] { new int?[] { null } },
    [ 1, null, 2 ], // new object[] { new int?[] { 1, null, 2 } },
    [ 1, 2 ],       // new object[] { new int[] { 1, 2 } },         -- NOTE: no PHP equivalence
  ];

  /**
   * [testWriteReadNullableColumn description]
   */
  public function testWriteReadNullableColumn(): void {
    foreach(static::$NullableColumnContentCases as $case) {
      $this->_WriteReadNullableColumn($case);
    }
  }

  /**
   * [_WriteReadNullableColumn description]
   * @param array $input [description]
   */
  public function _WriteReadNullableColumn(array $input): void
  {
    $id = DataField::createFromType('id', 'integer');
    // $id->hasNulls = true; // ?

    $ms = fopen('php://memory', 'r+');

    $writer = new ParquetWriter(new Schema([$id]), $ms);
    $rg = $writer->CreateRowGroup();
    $rg->WriteColumn(new DataColumn($id, $input));
    $rg->finish();
    $writer->finish();

    fseek($ms, 0);

    $reader = new ParquetReader($ms);
    $this->assertEquals(1, $reader->getRowGroupCount());
    $rg = $reader->OpenRowGroupReader(0);
    $this->assertEquals(count($input), $rg->getRowCount());
    $this->assertEquals($input, $rg->ReadColumn($id)->getData());
  }

  /**
   * [testFileMetadataSetsNumRowsOnFileAndRowGroup description]
   */
  public function testFileMetadataSetsNumRowsOnFileAndRowGroup(): void
  {
    $ms = fopen('php://memory', 'r+');
    $id = DataField::createFromType('id', 'integer');

    //write
    $writer = new ParquetWriter(new Schema([$id]), $ms);
    $rg = $writer->CreateRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 1, 2, 3, 4 ]));
    $rg->finish();
    $writer->finish();

    //read back
    $reader = new ParquetReader($ms);
    $this->assertEquals(4, $reader->getThriftMetadata()->num_rows);
    $rg = $reader->OpenRowGroupReader(0);
    $this->assertEquals(4, $rg->getRowCount());
  }

  /**
   * [testFileMetadataSetsNumRowsOnFileAndRowGroupMultipleRowGroups description]
   */
  public function testFileMetadataSetsNumRowsOnFileAndRowGroupMultipleRowGroups(): void
  {
    $ms = fopen('php://memory', 'r+');
    $id = DataField::createFromType('id', 'integer');

    //write
    $writer = new ParquetWriter(new Schema([$id]), $ms);

    $rg = $writer->CreateRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 1, 2, 3, 4 ]));
    $rg->finish();

    $rg = $writer->CreateRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 5, 6 ]));
    $rg->finish();

    $writer->finish();

    //read back
    $reader = new ParquetReader($ms);
    $this->assertEquals(6, $reader->getThriftMetadata()->num_rows);

    $rg = $reader->OpenRowGroupReader(0);
    $this->assertEquals(4, $rg->getRowCount());

    $rg = $reader->OpenRowGroupReader(1);
    $this->assertEquals(2, $rg->getRowCount());
  }


  public function testWriteReadDataPageV2(): void {
    $options = new \codename\parquet\ParquetOptions();
    $options->WriteDataPageV2 = true;

    $ms = fopen('php://memory', 'r+');
    $id = DataField::createFromType('id', 'integer');

    //write
    $writer = new ParquetWriter(new Schema([$id]), $ms, $options);

    $rg = $writer->CreateRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 1, 2, 3, 4 ]));
    $rg->finish();

    $rg = $writer->CreateRowGroup();
    $rg->WriteColumn(new DataColumn($id, [ 5, 6 ]));
    $rg->finish();

    $writer->finish();

    //read back
    $reader = new ParquetReader($ms);
    $this->assertEquals(6, $reader->getThriftMetadata()->num_rows);
    // $this->assertEquals(null, $reader->get

    $rg = $reader->OpenRowGroupReader(0);
    $col = $rg->ReadColumn($id);
    
    $this->assertEquals(4, $rg->getRowCount());

    $rg = $reader->OpenRowGroupReader(1);
    $this->assertEquals(2, $rg->getRowCount());
  }

}
