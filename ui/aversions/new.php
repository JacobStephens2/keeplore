<?php
require_once('../../private/initialize.php');
require_login();
$people = (new People($db, (int) $_SESSION['user_id']))->all();

if(is_post_request()) {
  $response = [];
  $response['Title'] = $_POST['Title'] ?? '';
  $response['AversionDate'] = $_POST['AversionDate'] ?? '';

  $response['Player1'] = $_POST['Player1'] ?? '';
  $response['Player2'] = $_POST['Player2'] ?? '';
  $response['Player3'] = $_POST['Player3'] ?? '';
  $response['Player4'] = $_POST['Player4'] ?? '';
  $response['Player5'] = $_POST['Player5'] ?? '';
  $response['Player6'] = $_POST['Player6'] ?? '';
  $response['Player7'] = $_POST['Player7'] ?? '';
  $response['Player8'] = $_POST['Player8'] ?? '';
  $response['Player9'] = $_POST['Player9'] ?? '';

  $playerCount = $_GET['playerCount'] ?? 1;

  $result = insert_aversion($response, $playerCount);

  if($result === true) {
    $new_id = mysqli_insert_id($db);
    $_SESSION['message'] = "The response was recorded successfully.";
    redirect_to(url_for('/aversions/index.php'));
  } else {
    $errors = $result;
  }

} else {
  // display the blank form
  $response = [];
  $response["Title"] = '';
  $response["AversionDate"] = '';
  $response["Player"] = '';
  $playerCount = $_GET['playerCount'] ?? 1;
}

$page_title = 'Record Aversion';
include(SHARED_PATH . '/header.php'); 

?>

<main>

    <h1><?php echo $page_title; ?></h1>

    <form 
      action="<?php echo url_for('/aversions/create.php'); ?>"
      method="get"
    >
			<label for="playerCount">User Count</label>
      <select name="playerCount" id="playerCount">
        <?php
          $i = 1;
          while ($i < 10) {
            echo "<option value=\"" . $i . "\"";
            if($i == $playerCount) {
              echo " selected";
            }
            echo ">" . $i . "</option>";
            $i++;
          }
        ?>
      </select>
      <input type="submit" value="Select User Count" />
    </form>

    <form action="<?php echo url_for('/aversions/new.php?playerCount=' . $playerCount); ?>" method="post">
      <?php echo csrf_input(); ?>

      <!-- This select gets populated by the JavaScript fetch request above -->
      <label for="SearchTitles">Search Items</label>      <input type="text" name="SearchTitles" id="SearchTitles">
      
			<label for="Title">Item</label>
			<select name="Title" id="Title">
			</select>

			<label for="AversionDate">Aversion Date</label>
			<input 
				type="date" 
				id="AversionDate" 
				name="AversionDate" 
				value="<?php echo date('Y') . '-' . date('m') . '-' . date('d'); ?>"
			/>

			<label for="Users">
				<?php
					if ($playerCount > 1) {
						echo 'Users';
					} else {
						echo 'User';
					}
				?>
			</label>

			<!-- Choose players -->
			<select id="Users" name="Player1">
				<option value='141'>
          Jacob Stephens
        </option>
				<?php
					foreach ($people as $person) {
						echo "<option value=\"" . h($person['id']) . "\">";
							echo h($person['name']);
						echo "</option>";
					}
				?>
			</select>

      <?php
        $i = 1;
        $p = 2;
        while ($playerCount > $i) { ?>
          <select name="Player<?php echo $p; ?>">
            <option value="">Choose a player</option>
            <?php
            foreach ($people as $person) {
              echo "<option value=\"" . h($person['id']) . "\">";
                echo h($person['name']);
              echo "</option>";
            }?>     
          </select> <?php
          $i++;
          $p++;
        }
      ?>

			<input type="submit" value="Record Use" />

    </form>

    <!-- Append options to artifact select element -->
    <script defer>
      function searchArtifacts(e) {
        if (document.querySelector('#SearchTitles').value == '') {
          getArtifacts();
        } else {
          requestBody = {
            "query": e.target.value,
            
          };
          fetch('https://<?php echo API_ORIGIN; ?>/artifacts.php', {
            method: 'POST',
            credentials: 'include',
            body: JSON.stringify(requestBody),
          })
            .then((response) => response.json())
            .then(
              (data => {
                const titleSelect = document.querySelector('select#Title');
                titleSelect.innerHTML = '';
                for (let i = 0; i < data.artifacts.length; i++) {
                  let option = document.createElement('option');
                  option.value = data.artifacts[i].id;
                  option.innerText = data.artifacts[i].Title;
                  titleSelect.append(option);
                }
              })
            )
          ;
        }
      }
      const searchTitlesInput = document.querySelector('input#SearchTitles');
      searchTitlesInput.addEventListener('input', searchArtifacts);

      function getArtifacts() {
        fetch('https://<?php echo API_ORIGIN; ?>/artifacts.php', {
          credentials: 'include',
        })
          .then((response) => response.json())
          .then(
            (data => {
              const titleSelect = document.querySelector('select#Title');
              titleSelect.innerHTML = '';
              for (let i in data) {
                let option = document.createElement('option');
                option.value = data.artifacts[i].id;
                option.innerText = data.artifacts[i].Title;
                titleSelect.append(option);
              }
            })
          )
        ;
      }
      getArtifacts();
    </script>

</main>

<?php include(SHARED_PATH . '/footer.php'); ?>