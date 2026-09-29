<?php

class database
{
    private $host = "localhost"; // nome do serviço no docker
    private $dbname = "primesys_crm";
    private $username = "primesys";
    private $password = "primesys201025";

    private $conn = null;

    public function connect()
    {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8",
                $this->username,
                $this->password
            );

            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $this->conn;

        } catch (PDOException $e) {
            die("Erro na conexão: " . $e->getMessage());
        }
    }

    public function disconnect()
    {
        $this->conn = null;
    }

    public function Query($sql)
    {
        if (!$this->conn) {
            $this->connect();
        }

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();

            // Se for SELECT, retorna array
            if (stripos(trim($sql), "SELECT") === 0) {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Para INSERT, UPDATE, DELETE
            return $stmt->rowCount();

        } catch (PDOException $e) {
            die("Erro na query: " . $e->getMessage());
        }
    }
}