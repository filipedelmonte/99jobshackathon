<?php
//Ä caracter para forçar detecção UTF8
if (!$isinside)
	die('Permiss&atilde;o negada.');

$dbconn = null;

define('DUPLICATE_ENTRY',1062);

function refValues($arr){
	//Only PHP 5.3+ work with reference
	if (strnatcmp(phpversion(),'5.3') >= 0) {
		$refs = array();
		foreach($arr as $key => $value)
			$refs[$key] = &$arr[$key];
		return $refs;
	}
	return $arr;
}

function dbOpen($timeout = 5, $transacao = FALSE) {
	global $dbconn;

	$dbhost = 'localhost';
	$dbuser = 'root';
	$dbpass = 'root';
	$dbname = 'kaiser';

	if ($dbconn) {
		return $dbconn;
	}
	else {
		$newconnection = mysqli_init();
		$newconnection->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeout);
		$newconnection->real_connect($dbhost,$dbuser,$dbpass,$dbname);

		if(mysqli_connect_errno()) {
			printf("BD: Erro de Conex&atilde;o: %s", mysqli_connect_error());
			die();
		}

		$newconnection->set_charset("utf8");
		if($transacao)
			$newconnection->autocommit(FALSE);

			$dbconn = $newconnection;

		return($newconnection);
	}
}

function escape_display($str) {
	return htmlspecialchars($str, ENT_QUOTES);
}

function debug_kidopi() {
	if ($_SESSION['cursols_nome'] == 'Kidopi') {
		echo "<pre>";
		debug_print_backtrace();
		echo "</pre>";
	}
}

function dbClose($conexao) {
	//mysqli_close($conexao);
}

class selectQuery extends executeQuery {
	function __construct($tabela, $campos, $extra = '', $noEscape = FALSE) {
		parent::__construct("SELECT $campos FROM $tabela $extra", $noEscape);
	}
}

class deleteQuery extends executeQuery {
	function __construct($tabela, $extra='') {
		parent::__construct("DELETE FROM $tabela $extra");
	}
}

class insertQuery extends executeQuery {
	function __construct($tabela, $nro_campos, $auto_increment = FALSE, $ignore = FALSE) {
		$campos = '';
		if ($auto_increment)
			$campos .= "'',";

		for ($i=0; $i<$nro_campos; $i++) {
			if ($i) $campos .= ',';
			$campos .= '?';
		}

		if ($ignore)
			parent::__construct("INSERT IGNORE INTO $tabela VALUES ($campos)");
		else
			parent::__construct("INSERT INTO $tabela VALUES ($campos)");
	}
}

class insertUpdateQuery extends executeQuery {
	function __construct($tabela, $nro_campos, $extra) {
		$campos = '';

		for ($i=0; $i<$nro_campos; $i++) {
			if ($i) $campos .= ',';
			$campos .= '?';
		}

		parent::__construct("INSERT INTO $tabela VALUES ($campos) ON DUPLICATE KEY UPDATE $extra");
	}
}


class updateQuery extends executeQuery {
	function __construct($tabela, $campos, $extra='') {
		parent::__construct("UPDATE $tabela SET $campos $extra");
	}
}

class executeQuery {
	protected $query;
	protected $nro_campos;

	protected $stmt;
	protected $result_metadata;
	protected $row;

	protected $tipos = array();
	protected $valores = array();
	protected $error = array();
	protected $noEscape = FALSE;

	protected $dbconn;

	function __construct($query, $noEscape = FALSE) {
		$this->noEscape = $noEscape;

		if (!is_string($query))
			die("BD: Query inv&aacute;lida!");

		$this->query = $query;
		$this->nro_campos = substr_count($query, '?');
	}

	private function campo($tipo,$valor) {
		if (count($this->valores) < $this->nro_campos) {
			$this->tipos[] = $tipo;
			$this->valores[] = $valor;
		}
		else{
			debug_kidopi();
			die("BD: N&uacute;mero de campos inv&aacute;lidos com a query!");
		}
	}

	function setError($cod, $msg) {
		$this->error[$cod] = $msg;
	}

	function inInt($valor) {
		$this->campo("i",$valor);
	}

	function inFloat($valor) {
		$this->campo("d",$valor);
	}

	function inBlob($valor) {
		$this->campo("b",$valor);
	}

	function inStr($valor) {
		$this->campo("s",$valor);
	}

	function inNull() {
		$ignora = count($this->valores);
		$i=0;
		$pos=0;
		while($i<=$ignora) {
			$pos = strpos($this->query,"?",$pos+1);
			$i++;
		}
		if($pos === FALSE){
			debug_kidopi();
			die("BD: N&uacute;mero de campos inv&aacute;lidos com a query!");
		}
		$this->query = substr_replace($this->query,'NULL',$pos,1);
		$this->nro_campos--;
	}

	function getQuery($onlyQuery = false) {
		if ($onlyQuery)
			return $this->query;
		else {
			$tmp = $this->query.'<br>';

			foreach ($this->valores as $n=>$chave)
				if($n!=0)
					$tmp .= 'Param '.$n.': '.$chave.'<br>';

			return $tmp;
		}
	}

	function execute() {
		if (count($this->valores) != $this->nro_campos){
			debug_kidopi();
			die("BD: N&uacute;mero de campos inv&aacute;lidos com a query!");
		}

		$this->dbconn = dbOpen();
		$this->stmt = $this->dbconn->prepare($this->query);

		if ($this->dbconn->errno != 0) {
			if ($this->error[$this->dbconn->errno])
				$this->error[$this->dbconn->errno];
			else{
				debug_kidopi();
				die('BD: Query invalida!<br>Error: #'.$this->dbconn->errno.' '.$this->dbconn->error);
			}
		}

		if ($this->nro_campos > 0) {
			array_unshift($this->valores,implode("", $this->tipos)); //coloca os tipos como primeiro parametro do mysqli->stmt_bind_param
			call_user_func_array(array($this->stmt, "bind_param"),refValues($this->valores));
		}

		$this->stmt->execute();

		if ($this->stmt->errno != 0) {
			if ($this->error[$this->stmt->errno])
				die($this->error[$this->stmt->errno]);
			else{
				debug_kidopi();
				die('BD: Erro no processamento da Query.<br>Error: #'.$this->stmt->errno.' '.$this->stmt->error);
			}
		}

		$nroRows = $this->stmt->affected_rows;

		if ($nroRows == -1) { //gera resultados - SELECT, DESCRIBE, SHOW
			$this->stmt->store_result();
			$this->result_metadata = $this->stmt->result_metadata();
			while ($field = $this->result_metadata->fetch_field()) {
				$params[] = &$this->row[$field->name];
			}

			call_user_func_array(array($this->stmt, 'bind_result'), $params);
		}
		else { // nao gera resultados - INSERT, DELETE, UPDATE
			$this->stmt->close();
			return $nroRows;
		}
	}

	function resNroCampos() {
		return $this->result_metadata->field_count;
	}

	function resNroRows() {
		if($this->stmt->num_rows)
			return $this->stmt->num_rows;
	}

	function resGetRow() {
		if ($this->stmt->fetch()) {
			$i = 0;
			foreach ($this->row as $val) {
				if ($this->noEscape)
					$result[$i++] = $val;
				else
					$result[$i++] = escape_display($val);
			}
			return $result;
		}
		else
			return false;
	}

	function resGetRowAssoc() {
		if ($this->stmt->fetch()) {
			foreach ($this->row as $key => $val) {
				if ($this->noEscape)
					$result[$key] = $val;
				else
					$result[$key] = escape_display($val);
			}
			return $result;
		}
		else
			return false;
	}

	function resMatriz() {
		$this->stmt->data_seek(0);

		$result = array();

		while ($this->stmt->fetch()) {
			foreach ($this->row as $key => $val) {
				if ($this->noEscape)
					$resultRow[$key] = $val;
				else
					$resultRow[$key] = escape_display($val);
			}
			$result[] = $resultRow;
		}

		return $result;
	}

	function resPosition($pos) {
		if ($pos >= 0 && $pos < $this->stmt->num_rows)
			$this->stmt->data_seek($pos);
	}

	function resEnd() {
		if($this->result_metadata){
			$this->result_metadata->close();
			$this->stmt->close();
		}
		//dbClose($this->dbconn);
	}
}

class transaction {
	protected $query;
	protected $nro_campos;

	protected $stmt;
	protected $result_metadata;
	protected $row;

	protected $tipos;
	protected $valores;
	protected $error;
	protected $noEscape;

	protected $dbconn;
	protected $data;

	function __construct() {
		$this->dbconn = dbOpen(10,true);
		$this->data = time();
	}

	function novaQuery($query) {
		if (!is_string($query))
			$this->rollback("BD: Query inv&aacute;lida.");

		$this->query = $query;
		$this->nro_campos = substr_count($query,'?');

		$this->stmt = NULL;
		$this->result_metadata = NULL;
		$this->row = NULL;

		$this->tipos = array();
		$this->valores = array();
		$this->error = array();
		$this->noEscape = FALSE;
	}

	function selectQuery($tabela, $campos, $extra = '', $noEscape = FALSE) {
		$this->novaQuery("SELECT $campos FROM $tabela $extra");
		$this->noEscape = $noEscape;
	}

	function deleteQuery($tabela, $extra='') {
		$this->novaQuery("DELETE FROM $tabela $extra");
	}

	function insertQuery($tabela, $nro_campos, $auto_increment=FALSE, $ignore=FALSE) {
		$campos = '';
		if ($auto_increment)
			$campos .= "'',";

		for ($i=0; $i<$nro_campos; $i++) {
			if ($i) $campos .= ',';
			$campos .= '?';
		}

		if ($ignore)
			$this->novaQuery("INSERT IGNORE INTO $tabela VALUES ($campos)");
		else
			$this->novaQuery("INSERT INTO $tabela VALUES ($campos)");
	}

	function insertUpdateQuery($tabela, $nro_campos, $extra) {
		$campos = '';

		for ($i=0; $i<$nro_campos; $i++) {
			if ($i) $campos .= ',';
			$campos .= '?';
		}

		$this->novaQuery("INSERT INTO $tabela VALUES ($campos) ON DUPLICATE KEY UPDATE $extra");
	}

	function updateQuery($tabela, $campos, $extra='') {
		$this->novaQuery("UPDATE $tabela SET $campos $extra");
	}

	private function campo($tipo,$valor) {
		if (count($this->valores) < $this->nro_campos) {
			$this->tipos[] = $tipo;
			$this->valores[] = $valor;
		}
		else{
			debug_kidopi();
			$this->rollback("BD: N&uacute;mero de campos inv&aacute;lidos com a query!");
		}
	}

	function setError($cod, $msg) {
		$this->error[$cod] = $msg;
	}

	function inInt($valor) {
		$this->campo("i",$valor);
	}

	function inFloat($valor) {
		$this->campo("d",$valor);
	}

	function inBlob($valor) {
		$this->campo("b",$valor);
	}

	function inStr($valor) {
		$this->campo("s",$valor);
	}

	function inNull() {
		$ignora = count($this->valores);
		$i=0;
		$pos=0;
		while($i<=$ignora) {
			$pos = strpos($this->query,"?",$pos+1);
			$i++;
		}
		if($pos === FALSE){
			debug_kidopi();
			$this->rollback("BD: N&uacute;mero de campos inv&aacute;lidos com a query!");
		}
		$this->query = substr_replace($this->query,'NULL',$pos,1);
		$this->nro_campos--;
	}

	function getQuery($onlyQuery = false) {
		if ($onlyQuery)
			return $this->query;
		else {
			$tmp = $this->query.'<br>';

			foreach ($this->valores as $n=>$chave)
				if($n!=0)
					$tmp .= 'Param '.$n.': '.$chave.'<br>';

			return $tmp;
		}
	}

	function execute() {
		if (count($this->valores) != $this->nro_campos){
			debug_kidopi();
			$this->rollback("BD: N&uacute;mero de campos inv&aacute;lidos com a query!");
		}

		$this->stmt = $this->dbconn->prepare($this->query);

		if ($this->dbconn->errno != 0) {
			if ($this->error[$this->dbconn->errno])
				$this->rollback($this->error[$this->dbconn->errno]);
			else{
				debug_kidopi();
				$this->rollback('BD: Query invalida!<br>Error: #'.$this->dbconn->errno.' '.$this->dbconn->error);
			}
		}

		if ($this->nro_campos > 0) {
			$tipos = implode("", $this->tipos); //coloca todos tipos numa string
			array_unshift($this->valores,$tipos); //coloca os tipos como primeiro parametro do mysqli->stmt_bind_param
			call_user_func_array(array($this->stmt, "bind_param"),refValues($this->valores));
		}

		$this->stmt->execute();

		if ($this->stmt->errno != 0) {
			if ($this->error[$this->stmt->errno])
				$this->rollback($this->error[$this->stmt->errno]);
			else{
				debug_kidopi();
				$this->rollback('BD: Erro no processamento da Query.<br>Error: #'.$this->stmt->errno.' '.$this->stmt->error);
			}
		}

		$nroRows = $this->stmt->affected_rows;

		if ($nroRows == -1) { //gera resultados - SELECT, DESCRIBE, SHOW
			$this->stmt->store_result();
			$this->result_metadata = $this->stmt->result_metadata();

			while ($field = $this->result_metadata->fetch_field()) {
				$params[] = &$this->row[$field->name];
			}

			call_user_func_array(array($this->stmt, 'bind_result'), $params);
		}
		else { // nao gera resultados - INSERT, DELETE, UPDATE
			$this->stmt->close();
			return $nroRows;
		}
	}

	function resNroCampos() {
		return $this->result_metadata->field_count;
	}

	function resNroRows() {
		if($this->stmt->num_rows)
			return $this->stmt->num_rows;
	}

	function resPosition($pos) {
		if ($pos >= 0 && $pos < $this->stmt->num_rows)
			$this->stmt->data_seek($pos);
	}

	function resGetRow() {
		if ($this->stmt->fetch()) {
			$i = 0;
			foreach ($this->row as $val) {
				if ($this->noEscape)
					$result[$i++] = $val;
				else
					$result[$i++] = escape_display($val);
			}
			return $result;
		}
		else
			return false;
	}

	function resGetRowAssoc() {
		if ($this->stmt->fetch()) {
			foreach ($this->row as $key => $val) {
				if ($this->noEscape)
					$result[$key] = $val;
				else
					$result[$key] = escape_display($val);
			}
			return $result;
		}
		else
			return false;
	}

	function resMatriz() {
		$this->stmt->data_seek(0);

		$result = array();

		while ($this->stmt->fetch()) {
			foreach ($this->row as $key => $val) {
				if ($this->noEscape)
					$resultRow[$key] = $val;
				else
					$resultRow[$key] = escape_display($val);
			}
			$result[] = $resultRow;
		}

		return $result;
	}

	function resEnd() {
		$this->result_metadata->close();
		$this->stmt->close();
	}

	function rollback($mensagem = NULL){
		$this->dbconn->rollback();
		//dbClose($this->dbconn);
		if($mensagem)
			die($mensagem);
	}

	function commit(){
		$this->dbconn->commit();
		//dbClose($this->dbconn);
	}

}
?>
