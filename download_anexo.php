<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { exit('Acesso negado'); }

require_once __DIR__ . '/php/database.php';

$id_anexo = (int)($_GET['id'] ?? 0);
if ($id_anexo <= 0) { exit('Anexo inválido'); }

$db = new database();
$res = $db->Query("SELECT NOME_ARQUIVO, EXTENSAO_ARQUIVO, ARQUIVO FROM ANEXOS_INTERACOES WHERE ID_ANEXO = $id_anexo");

if (!empty($res)) {
    $anexo = $res[0];
    header("Content-Type: application/octet-stream");
    header("Content-Disposition: attachment; filename=\"" . $anexo['NOME_ARQUIVO'] . "\"");
    echo $anexo['ARQUIVO'];
    exit;
}
echo "Arquivo não encontrado.";