<?php
// CRITICAL: session_start() must be called before any output (HTML/echo)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

class Mastermind
{
    // --- JSON FILE PATH ---
    private const PLAYERS_XP_FILE = 'players_xp.json'; // Changed from Leaderboard.json to reflect new structure

    // Class properties to store the dynamic settings
    private $codeLength;
    private $maxAttempts;
    private $digitPoolSize;
    private $mode;

    // Difficulty settings: [Mode ID => [Name, Length, Pool Size (0-N), Max Attempts]]
    private const DIFFICULTY_LEVELS = [
        1 => ['name' => 'Easy', 'key' => 'easy', 'length' => 3, 'pool' => 6, 'attempts' => 10], 
        2 => ['name' => 'Medium', 'key' => 'medium', 'length' => 4, 'pool' => 8, 'attempts' => 6], 
        3 => ['name' => 'Hard', 'key' => 'hard', 'length' => 5, 'pool' => 10, 'attempts' => 4], 
    ];

    // XP Calculation Configuration
    private const XP_CONFIG = [
        "easy" => ["max_xp" => 20, "step_xp" => 2.11, "max_tries" => 10],
        "medium" => ["max_xp" => 40, "step_xp" => 3.8, "max_tries" => 6],
        "hard" => ["max_xp" => 60, "step_xp" => 6.33, "max_tries" => 4]
    ];

    public function __construct(int $modeId = 2) // Default to Medium (ID 2)
    {
        $settings = self::DIFFICULTY_LEVELS[$modeId] ?? self::DIFFICULTY_LEVELS[2];

        $this->mode = $modeId;
        $this->codeLength = $settings['length'];
        $this->digitPoolSize = $settings['pool'];
        $this->maxAttempts = $settings['attempts'];
    }

    // --- XP CALCULATION FUNCTION ---
    private function calculateXP(string $modeKey, int $triesUsed): int
    {
        $config = self::XP_CONFIG[$modeKey] ?? null;

        if (!$config || $triesUsed < 1 || $triesUsed > $config["max_tries"]) {
            return 0;
        }

        $maxXP = $config["max_xp"];
        $stepXP = $config["step_xp"];
        
        // XP = Max XP - (Tries Used - 1) * Step XP
        $xp = $maxXP - ($triesUsed - 1) * $stepXP;

        // Ensure XP is not negative and round to nearest whole number (integer)
        return max(0, (int)round($xp));
    }

    // --- JSON PERSISTENCE (Players XP) ---

    private function loadPlayersFromFile(): array
    {
        if (file_exists(self::PLAYERS_XP_FILE)) {
            $data = file_get_contents(self::PLAYERS_XP_FILE);
            // Decode JSON, returning associative array if successful or empty array if fails
            return json_decode($data, true) ?? []; 
        }
        return [];
    }

    private function savePlayersToFile(array $players): void
    {
        // Sort the array by total_xp DESC before saving (optional, but good practice)
        usort($players, fn($a, $b) => $b['total_xp'] <=> $a['total_xp']);
        file_put_contents(self::PLAYERS_XP_FILE, json_encode($players, JSON_PRETTY_PRINT));
    }

    /**
     * Updates a player's total XP in the JSON file.
     */
    private function updatePlayerXP(string $playerName, int $xpEarned): void
    {
        $players = $this->loadPlayersFromFile();
        $playerFound = false;

        foreach ($players as &$player) {
            if ($player['name'] === $playerName) {
                $player['total_xp'] += $xpEarned;
                $playerFound = true;
                break;
            }
        }
        unset($player); // break reference

        if (!$playerFound) {
            // Player does not exist, insert new record
            $players[] = [
                'name' => $playerName,
                'total_xp' => $xpEarned,
            ];
        }

        $this->savePlayersToFile($players);
    }

    /**
     * Retrieves the top 10 players, sorted by total_xp.
     */
    public function getLeaderboard(): array
    {
        $players = $this->loadPlayersFromFile();

        // 1. Sort by total_xp (descending)
        usort($players, fn($a, $b) => $b['total_xp'] <=> $a['total_xp']);

        // 2. Add rank and return top 10
        $leaderboard = [];
        $rank = 1;
        foreach (array_slice($players, 0, 10) as $player) {
            $player['rank'] = $rank++;
            $leaderboard[] = $player;
        }
        
        return $leaderboard;
    }

    /**
     * Saves the game results and updates player XP upon winning.
     */
    private function saveScore(string $playerName, int $attempts): int
    {
        $modeKey = self::DIFFICULTY_LEVELS[$this->mode]['key'];
        $xpEarned = $this->calculateXP($modeKey, $attempts);
        
        $this->updatePlayerXP($playerName, $xpEarned);
        
        return $xpEarned;
    }
    
    // --- LOGIC FUNCTIONS (generateSecretCode and getFeedbackDetailed remain the same) ---
    private function generateSecretCode(): string
    {
        $digits = range(0, $this->digitPoolSize - 1); 
        shuffle($digits); 
        return implode('', array_slice($digits, 0, $this->codeLength));
    }

    private function getFeedbackDetailed(string $secret, string $guess): array
    {
        $correctPlacement = [];
        $correctDigit = [];
        $wrongDigit = [];

        $secretArray = str_split($secret);
        $guessArray = str_split($guess);
        
        $secretMatched = array_fill(0, $this->codeLength, false);
        $guessMatched = array_fill(0, $this->codeLength, false);

        // 1. Find Correct Placement (P)
        for ($i = 0; $i < $this->codeLength; $i++) {
            if ($secretArray[$i] === $guessArray[$i]) {
                $correctPlacement[] = $guessArray[$i];
                $secretMatched[$i] = true;
                $guessMatched[$i] = true;
            }
        }

        // 2. Find Correct Digit, Wrong Position (D) and Wrong Digit (W)
        for ($i = 0; $i < $this->codeLength; $i++) {
            if (!$guessMatched[$i]) {
                $found = false;
                for ($j = 0; $j < $this->codeLength; $j++) {
                    if (!$secretMatched[$j] && $guessArray[$i] === $secretArray[$j]) {
                        $correctDigit[] = $guessArray[$i];
                        $secretMatched[$j] = true; 
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $wrongDigit[] = $guessArray[$i];
                }
            }
        }
        
        return [$correctPlacement, $correctDigit, $wrongDigit];
    }

    // --- GAME FLOW / CONTROLLER (Updated for Player Name and XP) ---

    public function runWebGame()
    {
        // 1. Handle explicit Player Logout/Name Change Request
        if (isset($_POST['logout_player'])) {
            // Preserve mode setting if needed, but wipe game state and player identity
            $mode = $_SESSION['mode'] ?? $this->mode;
            session_destroy();
            session_start();
            // Redirect to home page with current mode
            header("Location: " . $_SERVER['PHP_SELF'] . "?mode=" . $mode);
            exit;
        }

        // 2. Handle player name submission (only happens when $_SESSION['player_name'] is NOT set)
        if (isset($_POST['set_name'])) {
            $name = trim($_POST['player_name']);
            // Basic validation 
            if (!empty($name) && strlen($name) <= 20 && preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                $players = $this->loadPlayersFromFile();
                $nameExists = false;
                // Check if name is already registered (case sensitive for simplicity, though case-insensitive might be better for uniqueness)
                foreach ($players as $player) {
                    if ($player['name'] === $name) {
                        $nameExists = true;
                        break;
                    }
                }
                
                if (!$nameExists) {
                    // Insert new player with 0 XP if truly new
                    $players[] = ['name' => $name, 'total_xp' => 0];
                    $this->savePlayersToFile($players);
                }
                
                $_SESSION['player_name'] = htmlspecialchars($name);
                
                // Redirect to home/game page to clear POST data
                header("Location: " . $_SERVER['PHP_SELF'] . "?mode=" . $this->mode);
                exit;
            } else {
                 // Simple session-based error message
                 $_SESSION['name_error'] = "Name must be 1-20 characters long and contain only letters, numbers, or underscores.";
                 header("Location: " . $_SERVER['PHP_SELF'] . "?mode=" . $this->mode);
                 exit;
            }
        }
        
        $playerName = $_SESSION['player_name'] ?? null;
        $nameError = $_SESSION['name_error'] ?? '';
        unset($_SESSION['name_error']); // Display and clear error

        // If the player name is not set, force them to the home page to input a name
        if (!$playerName) {
            $this->renderHomePage(true, $nameError); // true means render name input
            return;
        }

        // Check if the user is explicitly on the 'play' action URL
        $isHome = !isset($_GET['action']) || $_GET['action'] !== 'play';
        // Check if a game is currently running
        $gameRunning = isset($_SESSION['secret_code']) && !isset($_POST['reset']) && !($_SESSION['game_won'] ?? false) && $this->mode === ($_SESSION['mode'] ?? $this->mode);

        if ($isHome && !$gameRunning) {
            $this->renderHomePage(false, $nameError); // false means name is set, render normal home
            return;
        }

        // --- Game Setup / Reset Logic ---
        $errorMessage = '';
        $gameWon = $_SESSION['game_won'] ?? false;
        $secretCode = $_SESSION['secret_code'] ?? '';
        $currentMode = $_SESSION['mode'] ?? $this->mode;
        
        // Initialization / Mode Change / Reset
        if (empty($secretCode) || $currentMode !== $this->mode || isset($_POST['reset'])) {
            // Preserve player name across session destroy
            $tempName = $_SESSION['player_name'] ?? null; 
            session_destroy();
            session_start();
            $_SESSION['player_name'] = $tempName; 
            
            $secretCode = $this->generateSecretCode();
            $_SESSION['secret_code'] = $secretCode;
            $_SESSION['attempts'] = 0;
            $_SESSION['history'] = [];
            $_SESSION['game_won'] = false;
            $_SESSION['mode'] = $this->mode;
            $_SESSION['xp_earned'] = 0; // New: Reset XP earned display

            // Redirect to clear POST/GET data and start clean game view
            if(isset($_POST['reset']) || $currentMode !== $this->mode) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?mode=" . $this->mode . "&action=play");
                exit;
            }
        }

        // Handle Guess Input
        if (!$gameWon && $_SESSION['attempts'] < $this->maxAttempts && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guess'])) {
            $guess = trim($_POST['guess']);
            
            // Validation (as before)
            if (strlen($guess) !== $this->codeLength || !ctype_digit($guess)) {
                $errorMessage = "Invalid input. Please enter a precise " . $this->codeLength . "-digit number.";
            } elseif (count(array_unique(str_split($guess))) !== $this->codeLength) {
                 $errorMessage = "Invalid input. All " . $this->codeLength . " digits in your guess must be unique.";
            } elseif (max(array_map('intval', str_split($guess))) >= $this->digitPoolSize) {
                 $errorMessage = "Digits must be between 0 and " . ($this->digitPoolSize - 1) . ".";
            } else {
                $_SESSION['attempts']++;
                
                if ($guess === $secretCode) {
                    $_SESSION['game_won'] = true;
                    $gameWon = true;
                    // New: Save score and get earned XP
                    $xpEarned = $this->saveScore($playerName, $_SESSION['attempts']); 
                    $_SESSION['xp_earned'] = $xpEarned;
                }
                
                $feedback = $this->getFeedbackDetailed($secretCode, $guess);
                
                $_SESSION['history'][] = [
                    'guess' => $guess, 
                    'feedback' => $feedback, 
                    'attempt' => $_SESSION['attempts']
                ];
            }
        }

        $gameLost = !$gameWon && $_SESSION['attempts'] >= $this->maxAttempts;

        // --- RENDER GAME PAGE ---
        $this->renderHTML(
            $gameWon, 
            $gameLost, 
            $errorMessage, 
            $secretCode, 
            $_SESSION['attempts'], 
            $_SESSION['history'] ?? [],
            $playerName,
            $_SESSION['xp_earned'] ?? 0
        );
    }
    
    // --- VIEW / PRESENTATION (Home Page - Updated with Name Input and XP Leaderboard) ---

    private function renderHomePage(bool $showNameInput, string $nameError): void
    {
        $modeName = self::DIFFICULTY_LEVELS[$this->mode]['name'];
        $playerName = $_SESSION['player_name'] ?? '';

        echo "<!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Mastermind Code Breaker Challenge</title>
            <style>
                body { background-color: #121212; color: #FFFFFF; font-family: monospace; text-align: center; padding: 40px 20px; }
                .container { max-width: 600px; margin: 0 auto; display: flex; flex-direction: column; min-height: 90vh; justify-content: space-between; }
                .main-content { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; }
                .logo-container { margin-bottom: 20px; }
                .logo { width: 100%; max-width: 300px; height: auto; background-color:#333; line-height:80px; font-size: 2em; }
                h1 { font-size: 1.2em; color: #BBBBBB; margin-bottom: 40px; }
                
                /* Player Name Input Styles */
                .name-input-container { margin-bottom: 20px; padding: 20px; border: 1px solid #444; border-radius: 8px; background-color: #1a1a1a; }
                .name-input { padding: 10px; font-size: 1.2em; border: none; border-radius: 5px; margin-right: 10px; background-color: #333; color: #FFF; }
                .name-submit-button { background-color: #FF6600; color: #121212; border: none; padding: 10px 20px; cursor: pointer; border-radius: 5px; font-weight: bold; }
                .logout-button { background-color: #555; color: #EEE; border: none; padding: 5px 10px; cursor: pointer; border-radius: 5px; font-size: 0.8em; margin-top: 10px; }

                /* Mode Selector */
                .difficulty-selector { margin-bottom: 40px; }
                .difficulty-selector a { 
                    display: inline-block; padding: 10px 15px; margin: 0 5px; 
                    text-decoration: none; color: #00AAAA; border: 1px solid #00AAAA;
                    border-radius: 5px; transition: background-color 0.2s;
                }
                .difficulty-selector a:hover { background-color: #00AAAA; color: #121212; }
                .difficulty-selector .active { background-color: #00AA00; color: #121212; font-weight: bold; border-color: #00AA00; }
                
                /* Buttons */
                .play-button { 
                    background-color: #FF6600; color: #121212; border: none; padding: 15px 30px; 
                    cursor: pointer; font-family: inherit; border-radius: 8px; font-weight: bold; 
                    font-size: 1.5em; transition: background-color 0.2s; text-decoration: none; 
                    margin-bottom: 20px;
                }
                .play-button:hover { background-color: #FF8833; }
                .footer-link { color: #AAAAAA; text-decoration: none; font-size: 0.9em; cursor: pointer; }

                /* Modal Styles */
                .modal { display: none; position: fixed; z-index: 1; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8); }
                .modal-content { background-color: #1a1a1a; margin: 15% auto; padding: 20px; border: 1px solid #444; width: 80%; max-width: 500px; border-radius: 10px; text-align: left; }
                .close-button { color: #aaa; float: right; font-size: 28px; font-weight: bold; }
                .close-button:hover, .close-button:focus { color: #fff; text-decoration: none; cursor: pointer; }
                .rule-list li { margin-bottom: 15px; }
                
                /* Leaderboard Styles */
                .leaderboard-container { margin-top: 50px; text-align: center; }
                .leaderboard-container h3 { color: #FFD700; margin-bottom: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='main-content'>
                    <div class='logo-container'>
                        <img src='logo.png'style='width:100%; height:auto;' alt='Mastermind Logo'>
                    </div>
                    
                    
                    ";
                    
                    if (!empty($nameError)) {
                        echo "<p style='color: #FF0000; font-weight: bold;'>Error: {$nameError}</p>";
                    }

                    if ($showNameInput) {
                        echo "
                        <div class='name-input-container'>
                            <h3>Set/Select Your Unique Code Breaker Name</h3>
                            <p style='color:#777; font-size:0.8em; margin-bottom:10px;'>Use letters, numbers, and underscores only (max 20).</p>
                            <form method='POST'>
                                <input type='text' name='player_name' class='name-input' placeholder='Enter Name' maxlength='20' pattern='[a-zA-Z0-9_]+' required>
                                <button type='submit' name='set_name' class='name-submit-button'>SET NAME</button>
                            </form>
                            <p style='color:#777; font-size:0.8em; margin-top:10px;'>If the name exists, you will continue your progress.</p>
                        </div>
                        ";
                    } else {
                         echo "
                        <h1 style='color: #ffffffff;'>Welcome, {$playerName}! Total XP: " . $this->getPlayerTotalXP($playerName) . "</h1>
                        <form method='POST'>
                            <button type='submit' name='logout_player' class='logout-button'>Change Player / Logout</button> <br><br>
                        </form>
                        <div class='difficulty-selector'>
                            <strong>Selected Mode: {$modeName}</strong> <br><br>
                            <div style='margin-top: 10px;'>
                            ";
                            foreach (self::DIFFICULTY_LEVELS as $id => $level) {
                                $activeClass = ($id == $this->mode) ? 'active' : '';
                                echo "<a href='?mode={$id}' class='{$activeClass}'>{$level['name']}</a>";
                            }
                            echo "
                            </div>
                        </div>
                        
                        <a href='?mode={$this->mode}&action=play' class='play-button'>PLAY NOW</a>
                        ";
                    }
                    
                    // --- LEADERBOARD DISPLAY ---
                    $leaderboard = $this->getLeaderboard();
                    
                    echo "<div class='leaderboard-container'>
                            <h3>⭐ Global XP Leaderboard</h3>";

                    if (empty($leaderboard)) {
                        echo "<p style='color: #777;'>No XP recorded yet. Be the first to earn some!</p>";
                    } else {
                        echo "<table style='width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.9em; text-align: left;'>
                                <thead>
                                    <tr style='background-color: #2a2a2a;'>
                                        <th style='border-bottom: 2px solid #555; padding: 10px;'>Rank</th>
                                        <th style='border-bottom: 2px solid #555; padding: 10px;'>Player Name</th>
                                        <th style='border-bottom: 2px solid #555; padding: 10px; text-align: right;'>Total XP</th>
                                    </tr>
                                </thead>
                                <tbody>";
                        
                        foreach ($leaderboard as $score) {
                            $rank = $score['rank'];
                            $name = htmlspecialchars($score['name']);
                            $xp = number_format($score['total_xp']);
                            
                            $highlight = ($name === $playerName) ? 'style="background-color: #404000; font-weight: bold;"' : '';
                            
                            echo "<tr {$highlight}>
                                    <td style='border-bottom: 1px solid #333; padding: 10px;'>#{$rank}</td>
                                    <td style='border-bottom: 1px solid #333; padding: 10px;'>{$name}</td>
                                    <td style='border-bottom: 1px solid #333; padding: 10px; text-align: right; color: #FFD700;'>{$xp}</td>
                                  </tr>";
                        }
                        
                        echo "</tbody></table>";
                    }

                    echo "</div>";
                    // --- END LEADERBOARD DISPLAY ---
                    
                    
                    echo "
                </div><br><br>
                
                <footer>
                    <a class='footer-link' onclick='document.getElementById(\"rulesModal\").style.display=\"block\"'>Not sure how to play? (Rules and Guide)</a>
                </footer>
            </div>
            ";
            $this->renderRulesModal();
            echo "
            <script>
            // JavaScript to close the modal
            document.querySelector('.close-button').onclick = function() {
                document.getElementById('rulesModal').style.display = 'none';
            }
            window.onclick = function(event) {
                const modal = document.getElementById('rulesModal');
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }
            </script>
        </body></html>";
    }

    /**
     * Helper function to get a single player's XP for display
     */
    private function getPlayerTotalXP(string $playerName): string
    {
        $players = $this->loadPlayersFromFile();
        foreach ($players as $player) {
            if ($player['name'] === $playerName) {
                return number_format($player['total_xp']);
            }
        }
        return '0';
    }


private function renderRulesModal(): void
    {
        $currentLength = $this->codeLength;
        $currentPool = $this->digitPoolSize;
        $currentAttempts = $this->maxAttempts;
        
        // **CRITICAL FIX:** Define $modeName here to prevent the Warning
        $modeName = self::DIFFICULTY_LEVELS[$this->mode]['name']; 

        echo "
        <div id='rulesModal' class='modal'>
            <div class='modal-content'>
                <span class='close-button'>&times;</span>
                <h2>Mastermind Game Guide ({$modeName} Mode)</h2>
                
                <ul class='rule-list'>
                    <li style='margin-bottom: 20px;'>
                        <strong>The Goal:</strong> Your mission is to crack the secret code, getting the numbers and their positions exactly right, before you run out of your {$currentAttempts} attempts.
                    </li>
                    
                    <li>
                        <strong>1. The Secret Code Setup:</strong>
                        <ul style='list-style-type: disc; padding-left: 20px; margin-top: 5px; font-size: 0.95em;'>
                            <li>The code is {$currentLength} digits long.</li>
                            <li>It uses digits only from 0 to " . ($currentPool - 1) . ". (Example: if the pool is 6, you only use 0, 1, 2, 3, 4, 5).</li>
                            <li>Crucial Rule: All digits in the code are unique (no repeats, like 1-1-2-3).</li>
                        </ul>
                    </li>

                    <li style='margin-top: 25px;'>
                        <strong>2. The Feedback Key (Your Clues):</strong>
                        <p style='margin-top: 8px; font-size: 0.95em;'>After every guess, you receive clues that tell you how close you were. Use this information logically to refine your next guess:</p>
                        <ul style='list-style-type: none; padding-left: 20px; margin-top: 10px; font-size: 0.95em;'>
                            <li><span style='color:#00AA00; font-weight: bold;'>P (Correct Placement):</span> This digit is correct AND in the right position. (Excellent! You can safely keep this digit in this spot for the next round.)</li>
                            <li><span style='color:#FFFFFF; font-weight: bold;'>D (Correct Digit):</span> This digit is correct (it's in the secret code), but it is in the wrong position. (Keep this number, but you must move it to a different spot!)</li>
                            <li><span style='color:#444444; font-weight: bold;'>W (Wrong Digit):</span> This digit is not in the secret code at all. (Eliminate this number from your next guess.)</li>
                        </ul>
                    </li>
                    
                    <li style='margin-top: 25px;'>
                        <strong>3. How to Win:</strong> You win instantly when your feedback contains {$currentLength} 'P's (meaning every number is perfect). The fewer attempts you use, the higher your XP score!
                    </li>
                </ul>
            </div>
        </div>
        ";
    }


    // --- VIEW / PRESENTATION (Game Page - Updated Success Message) ---
    private function renderHTML(bool $gameWon, bool $gameLost, string $errorMessage, string $secretCode, int $attempts, array $history, string $playerName, int $xpEarned): void
    {
        $modeName = self::DIFFICULTY_LEVELS[$this->mode]['name'];

        echo "<!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Mastermind Code Breaker Challenge ({$modeName})</title>
            <style>
                body { background-color: #121212; color: #FFFFFF; font-family: monospace; text-align: center; padding: 40px 20px; }
                .container { max-width: 600px; margin: 0 auto; }
                .logo-container { margin-bottom: 20px; }
                .logo { width: 100%; max-width: 300px; height: auto; background-color:#333; line-height:80px; font-size: 2em; }
                h1 { font-size: 1.2em; color: #BBBBBB; margin-bottom: 40px; }
                h2 { font-size: 1em; margin-bottom: 20px; }
                
                .difficulty-selector { margin-bottom: 20px; }
                .difficulty-selector a { 
                    display: inline-block; padding: 5px 10px; margin: 0 5px; 
                    text-decoration: none; color: #00AAAA; border: 1px solid #00AAAA;
                    border-radius: 5px; transition: background-color 0.2s;
                }
                .difficulty-selector a:hover { background-color: #00AAAA; color: #121212; }
                .difficulty-selector .active { background-color: #00AA00; color: #121212; font-weight: bold; border-color: #00AA00; }

                .rules-box { border: 1px solid #444444; background-color: #1a1a1a; padding: 15px; margin-top: 30px; margin-bottom: 30px; border-radius: 8px; text-align: left; font-size: 0.9em; }
                .rules-box h3 { color: #00AAAA; margin-top: 0; margin-bottom: 10px; font-size: 1.1em; border-bottom: 1px solid #333333; padding-bottom: 5px; }
                .rules-box ul { list-style: none; padding: 0; margin: 0; }
                .rules-box li { margin-bottom: 8px; }

                .code-input-container { display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 30px; }
                .code-input-digit { width: 50px; height: 60px; font-size: 2em; text-align: center; border: 2px solid #555555; background-color: #333333; color: #FFFFFF; border-radius: 8px; transition: border-color 0.2s; }
                .code-input-digit:focus { outline: none; border-color: #AAAAAA; }
                .submit-button { background-color: #555555; color: #FFFFFF; border: none; padding: 10px 20px; cursor: pointer; font-family: inherit; border-radius: 5px; transition: background-color 0.2s; height: 60px; }
                .submit-button:hover { background-color: #777777; }

                .feedback-row { display: inline-flex; align-items: center; padding: 8px 15px; margin: 5px; border: 1px solid #444444; background-color: #222222; border-radius: 20px; font-size: 0.9em; }
                .feedback-digit { width: 20px; height: 20px; line-height: 20px; border-radius: 50%; margin: 0 3px; display: inline-block; font-weight: bold; text-align: center; color: #121212; }
                .p-correct { background-color: #00AA00; } 
                .d-correct { background-color: #FFFFFF; } 
                .w-wrong { background-color: #444444; color: #FFFFFF; } 
                .history-list { margin-top: 40px; display: flex; flex-wrap: wrap; justify-content: center; }
                .message { margin-top: 20px; font-weight: bold; }
                .success { color: #00FF00; }
                .failure { color: #FF0000; }
                .start-button { background-color: #00AAAA; color: #121212; border: none; padding: 10px 20px; margin-top: 20px; cursor: pointer; font-family: inherit; border-radius: 5px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='logo-container'>
                    <img src='logo.png' style='width:100%; max-width:300px; height:auto;' alt='Mastermind Logo'>
                </div>
                <h1>\"CRACK THE CODE. CLAIM THE CROWN\"</h1>
                
                <div class='difficulty-selector'>
                    <strong>Mode:</strong>
                    ";
                    foreach (self::DIFFICULTY_LEVELS as $id => $level) {
                        $activeClass = ($id == $this->mode) ? 'active' : '';
                        echo "<a href='?mode={$id}&action=play' class='{$activeClass}'>{$level['name']} ({$level['attempts']} Tries)</a>";
                    }
                echo "</div>
                
                <p style='color: #BBBBBB;'>Playing as: {$playerName}</p>

                

                <h2>Attempts: {$attempts} / {$this->maxAttempts}</h2>
        ";

        // --- Game Status Message ---
        if ($gameWon) {
            // Updated success message to show XP earned
            echo "<div class='message success'>SUCCESS, {$playerName}! Unlocked in {$attempts} tries! XP Earned: {$xpEarned}</div>";
        } elseif ($gameLost) {
            echo "<div class='message failure'>FAILURE! Max attempts reached! The secret code was: {$secretCode}</div>";
        }

        // --- Guess Form (Unchanged) ---
        if (!$gameWon && !$gameLost) {
            echo "<form method='POST' class='code-input-container' onsubmit='combineInput();'>";
            echo "<input type='hidden' name='guess' id='finalGuess'>";
            
            for ($i = 1; $i <= $this->codeLength; $i++) {
                echo "<input type='text' maxlength='1' class='code-input-digit' id='digit{$i}' onkeyup='moveToNext(this, \"digit" . ($i + 1) . "\")' pattern='[0-" . ($this->digitPoolSize - 1) . "]' required>";
            }

            echo "<button type='submit' class='submit-button' id='submitButton'>GUESS</button>";
            echo "</form>";
        } else {
             echo "<form method='POST'><button type='submit' name='reset' class='start-button'>START NEW GAME</button></form>";
        }
        
        // --- Error Message ---
        if (!empty($errorMessage)) {
            echo "<p class='message failure'>{$errorMessage}</p>";
        }

        // --- History / Feedback Renderer (Unchanged) ---
        echo "<div class='history-list'>";
        
        $reversedHistory = array_reverse($history);
        foreach ($reversedHistory as $item) {
            $p_temp = $item['feedback'][0]; 
            $d_temp = $item['feedback'][1]; 

            echo "<div class='feedback-row'>
                #{$item['attempt']}
                <div style='display:flex; margin-left:10px;'>";
                
            $guessChars = str_split($item['guess']);
            
            foreach ($guessChars as $digitIndex => $digit) {
                $class = 'w-wrong';
                
                if (in_array($digit, $p_temp)) {
                     $class = 'p-correct';
                     $p_temp = array_diff($p_temp, [$digit]); 
                } elseif (in_array($digit, $d_temp)) {
                     $class = 'd-correct';
                     $d_temp = array_diff($d_temp, [$digit]); 
                }
                
                echo "<span class='feedback-digit {$class}'>{$digit}</span>";
            }

            echo "</div></div>";
        }
        echo "</div>"; 
        
        // --- JAVASCRIPT: Input Combining Script (Unchanged) ---
        echo "<script>
            const CODE_LENGTH = {$this->codeLength};

            function moveToNext(current, nextId) {
                if (current.value.length >= current.maxLength) {
                    const nextInput = document.getElementById(nextId);
                    if (nextInput) {
                        nextInput.focus();
                    } else if (nextId === 'digit' + (CODE_LENGTH + 1)) {
                        document.getElementById('submitButton').focus();
                    }
                }
            }
            function combineInput() {
                let guess = '';
                for (let i = 1; i <= CODE_LENGTH; i++) {
                    const input = document.getElementById('digit' + i);
                    if (input) {
                        guess += input.value;
                    }
                }
                document.getElementById('finalGuess').value = guess;
            }
        </script>";
        echo "</body></html>";
    }
}

// --- Execution ---
// Get mode from URL, default to 2 (Medium)
$mode = $_GET['mode'] ?? 2; 
$game = new Mastermind((int)$mode);
$game->runWebGame();
?>