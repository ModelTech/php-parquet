<?php
namespace codename\parquet\format;

/**
 * Autogenerated by Thrift Compiler (0.15.0)
 *
 * DO NOT EDIT UNLESS YOU ARE SURE THAT YOU KNOW WHAT YOU ARE DOING
 *  @generated
 */
use Thrift\Base\TBase;
use Thrift\Type\TType;
use Thrift\Type\TMessageType;
use Thrift\Exception\TException;
use Thrift\Exception\TProtocolException;
use Thrift\Protocol\TProtocol;
use Thrift\Protocol\TBinaryProtocolAccelerated;
use Thrift\Exception\TApplicationException;

class ColumnChunk
{
    static public $isValidate = false;

    static public $_TSPEC = array(
        1 => array(
            'var' => 'file_path',
            'isRequired' => false,
            'type' => TType::STRING,
        ),
        2 => array(
            'var' => 'file_offset',
            'isRequired' => true,
            'type' => TType::I64,
        ),
        3 => array(
            'var' => 'meta_data',
            'isRequired' => false,
            'type' => TType::STRUCT,
            'class' => '\codename\parquet\format\ColumnMetaData',
        ),
        4 => array(
            'var' => 'offset_index_offset',
            'isRequired' => false,
            'type' => TType::I64,
        ),
        5 => array(
            'var' => 'offset_index_length',
            'isRequired' => false,
            'type' => TType::I32,
        ),
        6 => array(
            'var' => 'column_index_offset',
            'isRequired' => false,
            'type' => TType::I64,
        ),
        7 => array(
            'var' => 'column_index_length',
            'isRequired' => false,
            'type' => TType::I32,
        ),
        8 => array(
            'var' => 'crypto_metadata',
            'isRequired' => false,
            'type' => TType::STRUCT,
            'class' => '\codename\parquet\format\ColumnCryptoMetaData',
        ),
        9 => array(
            'var' => 'encrypted_column_metadata',
            'isRequired' => false,
            'type' => TType::STRING,
        ),
    );

    /**
     * File where column data is stored.  If not set, assumed to be same file as
     * metadata.  This path is relative to the current file.
     * 
     * 
     * @var string
     */
    public $file_path = null;
    /**
     * Byte offset in file_path to the ColumnMetaData *
     * 
     * @var int
     */
    public $file_offset = null;
    /**
     * Column metadata for this chunk. This is the same content as what is at
     * file_path/file_offset.  Having it here has it replicated in the file
     * metadata.
     * 
     * 
     * @var \codename\parquet\format\ColumnMetaData
     */
    public $meta_data = null;
    /**
     * File offset of ColumnChunk's OffsetIndex *
     * 
     * @var int
     */
    public $offset_index_offset = null;
    /**
     * Size of ColumnChunk's OffsetIndex, in bytes *
     * 
     * @var int
     */
    public $offset_index_length = null;
    /**
     * File offset of ColumnChunk's ColumnIndex *
     * 
     * @var int
     */
    public $column_index_offset = null;
    /**
     * Size of ColumnChunk's ColumnIndex, in bytes *
     * 
     * @var int
     */
    public $column_index_length = null;
    /**
     * Crypto metadata of encrypted columns *
     * 
     * @var \codename\parquet\format\ColumnCryptoMetaData
     */
    public $crypto_metadata = null;
    /**
     * Encrypted column metadata for this chunk *
     * 
     * @var string
     */
    public $encrypted_column_metadata = null;

    public function __construct($vals = null)
    {
        if (is_array($vals)) {
            if (isset($vals['file_path'])) {
                $this->file_path = $vals['file_path'];
            }
            if (isset($vals['file_offset'])) {
                $this->file_offset = $vals['file_offset'];
            }
            if (isset($vals['meta_data'])) {
                $this->meta_data = $vals['meta_data'];
            }
            if (isset($vals['offset_index_offset'])) {
                $this->offset_index_offset = $vals['offset_index_offset'];
            }
            if (isset($vals['offset_index_length'])) {
                $this->offset_index_length = $vals['offset_index_length'];
            }
            if (isset($vals['column_index_offset'])) {
                $this->column_index_offset = $vals['column_index_offset'];
            }
            if (isset($vals['column_index_length'])) {
                $this->column_index_length = $vals['column_index_length'];
            }
            if (isset($vals['crypto_metadata'])) {
                $this->crypto_metadata = $vals['crypto_metadata'];
            }
            if (isset($vals['encrypted_column_metadata'])) {
                $this->encrypted_column_metadata = $vals['encrypted_column_metadata'];
            }
        }
    }

    public function getName()
    {
        return 'ColumnChunk';
    }


    public function read($input)
    {
        $xfer = 0;
        $fname = null;
        $ftype = 0;
        $fid = 0;
        $xfer += $input->readStructBegin($fname);
        while (true) {
            $xfer += $input->readFieldBegin($fname, $ftype, $fid);
            if ($ftype == TType::STOP) {
                break;
            }
            switch ($fid) {
                case 1:
                    if ($ftype == TType::STRING) {
                        $xfer += $input->readString($this->file_path);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 2:
                    if ($ftype == TType::I64) {
                        $xfer += $input->readI64($this->file_offset);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 3:
                    if ($ftype == TType::STRUCT) {
                        $this->meta_data = new \codename\parquet\format\ColumnMetaData();
                        $xfer += $this->meta_data->read($input);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 4:
                    if ($ftype == TType::I64) {
                        $xfer += $input->readI64($this->offset_index_offset);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 5:
                    if ($ftype == TType::I32) {
                        $xfer += $input->readI32($this->offset_index_length);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 6:
                    if ($ftype == TType::I64) {
                        $xfer += $input->readI64($this->column_index_offset);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 7:
                    if ($ftype == TType::I32) {
                        $xfer += $input->readI32($this->column_index_length);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 8:
                    if ($ftype == TType::STRUCT) {
                        $this->crypto_metadata = new \codename\parquet\format\ColumnCryptoMetaData();
                        $xfer += $this->crypto_metadata->read($input);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                case 9:
                    if ($ftype == TType::STRING) {
                        $xfer += $input->readString($this->encrypted_column_metadata);
                    } else {
                        $xfer += $input->skip($ftype);
                    }
                    break;
                default:
                    $xfer += $input->skip($ftype);
                    break;
            }
            $xfer += $input->readFieldEnd();
        }
        $xfer += $input->readStructEnd();
        return $xfer;
    }

    public function write($output)
    {
        $xfer = 0;
        $xfer += $output->writeStructBegin('ColumnChunk');
        if ($this->file_path !== null) {
            $xfer += $output->writeFieldBegin('file_path', TType::STRING, 1);
            $xfer += $output->writeString($this->file_path);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->file_offset !== null) {
            $xfer += $output->writeFieldBegin('file_offset', TType::I64, 2);
            $xfer += $output->writeI64($this->file_offset);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->meta_data !== null) {
            if (!is_object($this->meta_data)) {
                throw new TProtocolException('Bad type in structure.', TProtocolException::INVALID_DATA);
            }
            $xfer += $output->writeFieldBegin('meta_data', TType::STRUCT, 3);
            $xfer += $this->meta_data->write($output);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->offset_index_offset !== null) {
            $xfer += $output->writeFieldBegin('offset_index_offset', TType::I64, 4);
            $xfer += $output->writeI64($this->offset_index_offset);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->offset_index_length !== null) {
            $xfer += $output->writeFieldBegin('offset_index_length', TType::I32, 5);
            $xfer += $output->writeI32($this->offset_index_length);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->column_index_offset !== null) {
            $xfer += $output->writeFieldBegin('column_index_offset', TType::I64, 6);
            $xfer += $output->writeI64($this->column_index_offset);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->column_index_length !== null) {
            $xfer += $output->writeFieldBegin('column_index_length', TType::I32, 7);
            $xfer += $output->writeI32($this->column_index_length);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->crypto_metadata !== null) {
            if (!is_object($this->crypto_metadata)) {
                throw new TProtocolException('Bad type in structure.', TProtocolException::INVALID_DATA);
            }
            $xfer += $output->writeFieldBegin('crypto_metadata', TType::STRUCT, 8);
            $xfer += $this->crypto_metadata->write($output);
            $xfer += $output->writeFieldEnd();
        }
        if ($this->encrypted_column_metadata !== null) {
            $xfer += $output->writeFieldBegin('encrypted_column_metadata', TType::STRING, 9);
            $xfer += $output->writeString($this->encrypted_column_metadata);
            $xfer += $output->writeFieldEnd();
        }
        $xfer += $output->writeFieldStop();
        $xfer += $output->writeStructEnd();
        return $xfer;
    }
}
