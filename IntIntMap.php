<?php


/**
 * Требуется написать IntIntMap, который по произвольному int ключу хранит произвольное int значение
 * Важно: все данные (в том числе дополнительные, если их размер зависит от числа элементов) требуется хранить в выделенном заранее блоке в разделяемой памяти
 * для доступа к памяти напрямую необходимо (и достаточно) использовать следующие два метода:
 * \shmop_read и \shmop_write
 */

/**
 * Class IntIntMap
 * Eazyest way - it's split reserved memory to chunks and translate key to offset in memory,
 * for more efficient convert values to 36 base, then we can reduce chunk size to 13 symbols,
 * it's almost 20% profit (comparison with hex)
 */
class IntIntMap
{
    /** @var int string size of 36 base PHP_INT_MAX */
    private const CHUNK_SIZE = 13;
    /** @var resource shmop_open result store */
    private $shmopID;
    /** @var int Quantity of chunks reserved memory */
    private $chunkQuantity = 0;

    /**
     * IntIntMap constructor.
     * @param resource $shm_id результат вызова \shmop_open
     * @param int $size размер зарезервированного блока в разделяемой памяти (~100GB)
     * @throws Exception
     */
    public function __construct(resource $shm_id, int $size)
    {
        $this->shmopID = $shm_id;
        // Reserved memory cannot be less than one chunk
        if($size <= self::CHUNK_SIZE){
            throw new \Exception('Invalid shmop size');
        }
        // Split reserved memory to chunks, and reduce at 1 chunk to not overflow reserved memory
        $this->chunkQuantity = intdiv($size, self::CHUNK_SIZE) - 1;
    }

    /**
     * GetOffset method
     * Translate key to offset in shared memory
     * ! Solution useless in small chunk quantities
     *
     * @link http://sandbox.onlinephpfunctions.com/code/a73ff2bff29e174f9f2a335d3adef5f8e580ddb1 - sandbox to check examples of results
     * @param int $key
     * @return int
     */
    private function getOffset(int $key): int
    {
        // hashing int
        $key = (~$key) + ($key << 21);
        $key = $key ^ $this->unsignedRightShift($key, 24);
        $key = ($key + ($key << 3)) + ($key << 8);
        $key = $key ^ $this->unsignedRightShift($key, 14);
        $key = ($key + ($key << 2)) + ($key << 4);
        $key = $key ^ $this->unsignedRightShift($key, 28);
        $key = $key + ($key << 63);
        // To key will always less than chunk quantity
        $key = $key & $this->chunkQuantity;
        // There $key is a number of chunk, let's multiply to chunk size to get they offset
        return (int) $key * self::CHUNK_SIZE;
    }

    /**
     * Unsigned right shift basic implementation
     * @param int $a - value
     * @param int $b - steps
     * @return int
     */
    private function unsignedRightShift(int $a, int $b): int
    {
        return(($b)?($a>>$b)&~(1<<(8*PHP_INT_SIZE-1)>>($b-1)):$a);
    }

    /**
     * Метод должен работать со сложностью O(1) при отсутствии коллизий, но может деградировать при их появлении
     * @param int $key произвольный ключ
     * @param int $value произвольное значение
     * @return int|null предыдущее значение
     */
    public function put(int $key, int $value): ?int
    {
        // get previous value
        $prev = $this->get($key);
        \shmop_write($this->shmopID, $this->encodeValue($value), $this->getOffset($key));
        return $prev;
    }

    /**
     * Метод должен работать со сложностью O(1) при отсутствии коллизий, но может деградировать при их появлении
     * @param int $key ключ
     * @return int|null значение, сохраненное ранее по этому ключу
     */
    public function get(int $key): ?int
    {
        if($value = \shmop_read($this->shmopID, $this->getOffset($key), self::CHUNK_SIZE)){
            $res = $this->decodeValue($value);
        }
        return $res ?? null;
    }

    /**
     * convert int from 10 to 36 base (in 36 base string representation of ints are shorter)
     * @param int $value
     * @return string
     */
    private function encodeValue(int $value): string
    {
        return str_pad(base_convert(strval($value), 10, 36), self::CHUNK_SIZE, '0', STR_PAD_LEFT);
    }

    /**
     * convert int from 36 to 10 base
     * @param string $value
     * @return int
     */
    private function decodeValue(string $value): int
    {
        return intval(base_convert($value, 36, 10));
    }
}