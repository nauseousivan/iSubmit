<style>
        #studentForm008Modal {
            transform: translateY(100%);
            transition: transform 0.35s cubic-bezier(0.2,0.8,0.2,1);
        }
        #studentForm008Modal.open {
            transform: translateY(0);
        }
    </style>
    <div id="studentForm008Modal" style="display:none; position:fixed; top:5vh; left:0; right:0; bottom:0; background:white; z-index:99999; flex-direction:column; border-radius:24px 24px 0 0; box-shadow:0 -4px 20px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="background:white; width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden;">
            <div style="background:#0f172a; color:white; padding:20px 25px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:11px; font-family:'Inter', sans-serif; text-transform:uppercase; letter-spacing:0.05em; opacity:0.8;">Feedback Panel</span>
                    <h3 style="margin:0; font-family:'Inter', sans-serif; font-size:18px;">Form 008 Evaluation Sheet</h3>
                </div>
            </div>
            <div style="flex:1; overflow-y:auto; padding:25px; background:#f8fafc; font-family:'Inter', sans-serif;">
                <div style="display:flex; justify-content:space-between; align-items:center; background:white; padding:20px; border-radius:16px; border:1px solid #e2e8f0; margin-bottom:24px;">
                    <div>
                        <span style="font-size:12px; color:#64748b; font-weight:800; text-transform:uppercase;">Evaluated Score</span>
                        <h2 style="margin:4px 0 0 0; color:#0f172a; font-size:28px;" id="sModalScore">0 / 22</h2>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-size:12px; color:#64748b; font-weight:800; text-transform:uppercase;">Overall Decision</span>
                        <h3 style="margin:4px 0 0 0; color:#d97706;" id="sModalDecision">Pending</h3>
                    </div>
                </div>
                <div id="sModalContent" style="display:flex; flex-direction:column; gap:20px;"></div>
            </div>
        </div>
    </div>

    <script>
        const form008Questions = {
            "Clarity of Research Objectives": {
                "q1": "Are the research questions or objectives clearly articulated and well-defined?",
                "q2": "Is there a logical rationale for the study?"
            },
            "Literature Review": {
                "q3": "Does the literature review demonstrate a thorough understanding of existing research?",
                "q4": "Is the literature review up-to-date and relevant?"
            },
            "Theoretical Framework": {
                "q5": "Is there a well-developed theoretical framework guiding the research?",
                "q6": "Does the theoretical framework align with research questions?"
            },
            "Research Design and Methodology": {
                "q7": "Is the research design appropriate for addressing objectives?",
                "q8": "Are methods described in sufficient detail?",
                "q9": "Is sample size and sampling method justified?"
            },
            "Data Collection": {
                "q10": "Are data collection methods clearly described and appropriate?",
                "q11": "Is there a plan for ensuring credentials and validity?"
            },
            "Data Analysis": {
                "q12": "Is data analysis approach suitable?",
                "q13": "Are statistical methods appropriate?"
            },
            "Significance of the Study": {
                "q14": "Does proposal articulate potential contributions to the field?",
                "q15": "Is there discussion of practical implications?"
            },
            "Feasibility": {
                "q16": "Are required resources realistically addressed?",
                "q17": "Does researcher have access to necessary data/facilities?"
            },
            "Ethical Considerations": {
                "q18": "Are ethical considerations adequately addressed?",
                "q19": "Are there plans for consent and confidentiality?"
            },
            "Presentation and Communication": {
                "q20": "Is proposal organized and clearly written?",
                "q21": "Are ideas presented coherently?",
                "q22": "Is the language appropriate and accessible?"
            }
        };

        window.openStudentForm008 = function(jsonString, score, decision) {
            try {
                const data = typeof jsonString === 'string' ? JSON.parse(jsonString) : jsonString;
                document.getElementById('sModalScore').textContent = `${score} / 22`;
                const decEl = document.getElementById('sModalDecision');
                decEl.textContent = decision ? decision.toUpperCase() : "EVALUATED";
                if (score >= 15) decEl.style.color = "#059669";
                else if (score >= 8) decEl.style.color = "#d97706";
                else decEl.style.color = "#dc2626";

                const container = document.getElementById('sModalContent');
                container.innerHTML = '';

                for (const [sectionTitle, questions] of Object.entries(form008Questions)) {
                    let sectionHTML = `
                        <div style="background:white; border:1px solid #e2e8f0; border-radius:16px; padding:20px;">
                            <h4 style="margin:0 0 16px 0; color:#0f172a; font-size:15px; border-bottom:1px solid #e2e8f0; padding-bottom:12px; font-weight:800;">${sectionTitle}</h4>
                            <div style="display:flex; flex-direction:column; gap:16px;">
                    `;
                    for (const [qKey, qText] of Object.entries(questions)) {
                        const answerData = data && data[qKey] ? data[qKey] : {
                            val: "N/A",
                            comment: ""
                        };
                        let badgeStyle = "background:#f1f5f9; color:#475569;";
                        if (answerData.val === "YES") badgeStyle = "background:#ecfdf5; color:#047857; border:1px solid #a7f3d0;";
                        if (answerData.val === "NO") badgeStyle = "background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;";

                        let commentHTML = "";
                        if (answerData.comment && answerData.comment.trim() !== "") {
                            commentHTML = `
                                <div style="margin-top:10px; background:#fffbeb; border-left:4px solid #f59e0b; padding:12px; border-radius:8px; font-size:13px; color:#92400e; font-style:italic;">
                                    <strong>Evaluator Note:</strong> "${answerData.comment}"
                                </div>
                            `;
                        }

                        sectionHTML += `
                            <div style="display:flex; flex-direction:column;">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <span style="padding:6px 12px; border-radius:8px; font-size:12px; font-weight:800; height:fit-content; ${badgeStyle}">${answerData.val}</span>
                                    <p style="margin:0; font-size:14px; color:#334155; line-height:1.5;">${qText}</p>
                                </div>
                                ${commentHTML}
                            </div>
                        `;
                    }
                    sectionHTML += `</div></div>`;
                    container.innerHTML += sectionHTML;
                }
                window.openForm008Modal();
            } catch (e) {
                console.error(e);
                alert("Error loading evaluation details.");
            }
        };

        window.openForm008Modal = function() {
            const m = document.getElementById('studentForm008Modal');
            m.style.display = 'flex';
            setTimeout(() => m.classList.add('open'), 10);
            try { history.pushState({ layer: 'form008' }, '', ''); } catch(e) {}
        };

        window.closeForm008Modal = function(fromPopstate = false) {
            const m = document.getElementById('studentForm008Modal');
            m.classList.remove('open');
            setTimeout(() => { m.style.display = 'none'; }, 350);
            if (!fromPopstate) history.back();
        };

    </script>