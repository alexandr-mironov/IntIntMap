<?php


/**
 * Требуется написать IntIntMap, который по произвольному int ключу хранит произвольное int значение
 * Важно: все данные (в том числе дополнительные, если их размер зависит от числа элементов) требуется хранить в выделенном заранее блоке в разделяемой памяти
 * для доступа к памяти напрямую необходимо (и достаточно) использовать следующие два метода:
 * \shmop_read и \shmop_write
 */
class IntIntMap
{
    // string size of 36 base PHP_INT_MAX
    private const CHUNK_SIZE = 13;
    //
    private $shmopID;
    // Quantity of chunks reserved memory
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
        if($size <= 0){
            throw new \Exception('Invalid shmop size');
        }
        // Split reserved memory to chunks
        $this->chunkQuantity = intdiv($size, self::CHUNK_SIZE) - 1;
    }

    /**
     * GetOffset method
     * hash key to some offset shorter then
     * @param int $key
     * @return int
     */
    private function getOffset(int $key): int
    {
        $key = (~$key) + ($key << 21);
        $key = $key ^ $this->usr($key, 24);
        $key = ($key + ($key << 3)) + ($key << 8);
        $key = $key ^ $this->usr($key, 14);
        $key = ($key + ($key << 2)) + ($key << 4);
        $key = $key ^ $this->usr($key, 28);
        $key = $key + ($key << 63);
        $key = $key & $this->chunkQuantity;
        return (int) $key * self::CHUNK_SIZE;
    }

    /**
     * Unsigned shift right basic implementation
     * @param int $a - value
     * @param int $b - steps
     * @return int
     */
    private function usr(int $a, int $b)
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
     * @param string $value
     * @return int
     */
    private function decodeValue(string $value): int
    {
        return intval(base_convert($value, 36, 10));
    }
}