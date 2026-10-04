<?php
require_once('../../private/initialize.php');
require_login();
$people = (new People($db, (int) $_SESSION['user_id']))->all();
$playerCount = min(9, max(1, (int) ($_GET['playerCount'] ?? 1)));

if(is_post_request()) {
  $person_ids = [];
  for ($i = 1; $i <= $playerCount; $i++) {
    $person_ids[] = $_POST['Player' . $i] ?? '';
  }
  try {
    (new Aversions($db, (int) $_SESSION['user_id']))->record((int) ($_POST['Title'] ?? 0), (string) ($_POST['AversionDate'] ?? ''), $person_ids);
    $_SESSION['message'] = "The aversion was recorded successfully.";
    redirect_to(url_for('/aversions/index.php'));
  } catch (InvalidArgumentException $error) {
    $errors[] = $error->getMessage();
  }
}

$page_title = 'Record Aversion';
include(SHARED_PATH . '/header.php'); 

?>

<main>

    <h1><?php echo $page_title; ?></h1>

    <?php echo display_errors($errors); ?>

    <form 
      action="<?php echo url_for('/aversions/new.php'); ?>"
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
				<option value="">Choose a person</option>
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
            <option value="">Choose a person</option>
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