<!-- assets/mascot/mascot.php -->
<div id="quill-container">
    <svg viewBox="0 0 120 140" xmlns="http://www.w3.org/2000/svg" id="quill-svg">
        <defs>
            <filter id="ground-shadow" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="3" />
            </filter>
            <filter id="core-shadow" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="1.5" />
            </filter>
        </defs>
        
        <!-- Premium Ground Contact Shadow -->
        <ellipse cx="60" cy="134" rx="36" ry="4" fill="#B8A9FF" opacity="0.6" filter="url(#ground-shadow)" />
        <ellipse cx="60" cy="134" rx="22" ry="2" fill="#5750d4" opacity="0.4" filter="url(#core-shadow)" />
        
        <!-- Feet -->
        <g id="quill-feet">
            <ellipse cx="46" cy="130" rx="10" ry="5" fill="#5750d4" id="quill-footL" style="transform-origin: 46px 130px; transition: transform 0.3s ease;" />
            <ellipse cx="74" cy="130" rx="10" ry="5" fill="#5750d4" id="quill-footR" style="transform-origin: 74px 130px; transition: transform 0.3s ease;" />
        </g>

        <!-- Entire Upper Body Group -->
        <g id="quill-body-group" style="transform-origin: 60px 130px; transition: transform 0.3s ease;">
            <!-- Graduation cap -->
            <g style="transform-origin: 60px 25px; transition: transform 0.3s;" id="quill-cap">
                <rect x="28" y="24" width="64" height="10" rx="3" fill="#1e1b4b" />
                <polygon points="60,10 90,26 60,30 30,26" fill="#312e81" />
                <line x1="90" y1="26" x2="96" y2="46" stroke="#312e81" stroke-width="2" />
                <circle cx="96" cy="48" r="4" fill="#a78bfa" />
            </g>

            <!-- Body -->
            <ellipse cx="60" cy="90" rx="34" ry="42" fill="#6C63FF" />
            <!-- Chest / belly -->
            <ellipse cx="60" cy="96" rx="20" ry="26" fill="#ede9fe" />

            <!-- Eyes group -->
            <g id="quill-eyes-squash" style="transform-origin: 60px 72px;">
                <circle cx="44" cy="72" r="11" fill="white" />
                <circle cx="76" cy="72" r="11" fill="white" />
                
                <!-- Pupils that can move around -->
                <g id="quill-pupils">
                    <circle cx="46" cy="73" r="6" fill="#1e1b4b" />
                    <circle cx="78" cy="73" r="6" fill="#1e1b4b" />
                    <!-- Shine -->
                    <circle cx="48" cy="70" r="2.5" fill="white" />
                    <circle cx="80" cy="70" r="2.5" fill="white" />
                </g>
            </g>

            <!-- Beak -->
            <polygon points="60,78 53,86 67,86" fill="#f59e0b" />

            <!-- Left wing -->
            <g id="quill-wingL" style="transform-origin: 27px 90px;">
                <ellipse cx="27" cy="98" rx="12" ry="20" fill="#5750d4" transform="rotate(-15,27,98)" />
            </g>

            <!-- Right wing -->
            <g id="quill-wingR" style="transform-origin: 93px 90px;">
                <ellipse cx="93" cy="98" rx="12" ry="20" fill="#5750d4" transform="rotate(15,93,98)" />
            </g>
        </g>
    </svg>
</div>
