import { TranscriptUI } from './TranscriptUI.js?v=1.1.16';
import { TranscriptService } from './TranscriptService.js?v=1.0.6';
import { HistoryManager } from './HistoryManager.js?v=1.0.6';
import { SegmentProcessor } from './SegmentProcessor.js?v=1.0.7';
import { ExportManager } from './ExportManager.js?v=1.0.6';
import { CustomSelectionHandles } from './CustomSelectionHandles.js?v=1.0.6';

export class TranscriptApp {
    constructor() {
        this.state = {
            selectedAudioFile: null,
            selectedAudioFiles: [],
            selectedFileGroups: [],
            activeSavePromise: null,
            transcriptHistoryRenderSeq: 0,
            currentTranscriptSegments: [],
            currentTranscriptText: '',
            currentTranscriptSlug: null,
            editModeActive: true,
            transcriptUndoStack: [],
            exportData: null,
            exportType: null,
            lastRenderedSpeakerBlocks: [],
            speakerColorMap: new Map()
        };

        this.ui = new TranscriptUI(this);
        this.service = new TranscriptService(this);
        this.history = new HistoryManager(this);
        this.processor = new SegmentProcessor(this);
        this.exportManager = new ExportManager(this);
        this.selectionHandles = new CustomSelectionHandles(this);

        this.initGlobalBindings();
        this.initEventListeners();
        
        // Initial setup
        this.history.renderHistory();
        this.service.loadTranscriptConfig();
    }

    initGlobalBindings() {
        // Expose all functions that the HTML template needs via window
        window.switchTranscriptView = this.ui.switchTranscriptView.bind(this.ui);
        window.openTranscriptSettings = this.ui.openTranscriptSettings.bind(this.ui);
        window.closeTranscriptSettings = this.ui.closeTranscriptSettings.bind(this.ui);
        window.saveTranscriptSettings = this.service.saveTranscriptSettings.bind(this.service);
        window.switchTab = this.ui.switchTab.bind(this.ui);

        window.updateSidebarSaveButtonState = this.ui.updateSidebarSaveButtonState.bind(this.ui);
        window.saveTranscriptChanges = this.processor.saveCurrentSegmentsToServer.bind(this.processor);
        window.updateSegmentText = this.processor.updateSegmentText.bind(this.processor);
        window.showTranscriptMode = this.ui.showTranscriptMode.bind(this.ui);
        window.showTranscriptChoice = this.ui.showTranscriptChoice.bind(this.ui);
        window.removeSelectedFile = this.ui.removeSelectedFile.bind(this.ui);
        window.highlightSegment = this.ui.highlightSegment.bind(this.ui);
        window.undoLastMove = this.processor.undoLastMove.bind(this.processor);
        window.optimizeSpeakersWithAI = this.processor.optimizeSpeakersWithAI.bind(this.processor);
        window.moveSegment = this.processor.moveSegment.bind(this.processor);
        window.copyBlockText = this.ui.copyBlockText.bind(this.ui);
        window.toggleAudioPlayer = this.ui.toggleAudioPlayer.bind(this.ui);

        window.filterHistory = this.history.filterHistory.bind(this.history);
        window.renderHistory = this.history.renderHistory.bind(this.history);
        window.loadTranscript = this.history.loadTranscript.bind(this.history);
        window.editTranscriptionTitle = this.history.editTranscriptionTitle.bind(this.history);
        window.requestDeleteTranscription = this.history.requestDeleteTranscription.bind(this.history);
        
        window.exportToSRT = this.exportManager.exportToSRT.bind(this.exportManager);
        window.triggerExportDownload = this.exportManager.triggerExportDownload.bind(this.exportManager);
        window.exportToVerlauf = this.exportManager.exportToVerlauf.bind(this.exportManager);
        window.exportToErgebnis = this.exportManager.exportToErgebnis.bind(this.exportManager);
        window.renderErgebnisprotokoll = this.exportManager.renderErgebnisprotokoll.bind(this.exportManager);
        window.generateErgebnisprotokoll = this.exportManager.generateErgebnisprotokoll.bind(this.exportManager);
        window.selectExportOption = this.exportManager.selectExportOption.bind(this.exportManager);
        
        window.showReassignSubmenu = this.processor.showReassignSubmenu.bind(this.processor);
        window.reassignSpeaker = this.processor.reassignSpeaker.bind(this.processor);
        window.showNewSpeakerInline = this.processor.showNewSpeakerInline.bind(this.processor);
        window.confirmInlineSpeaker = this.processor.confirmInlineSpeaker.bind(this.processor);
        window.showRenameSpeakerInline = this.processor.showRenameSpeakerInline.bind(this.processor);
        window.confirmRenameSpeaker = this.processor.confirmRenameSpeaker.bind(this.processor);
        window.insertSpeakerAt = this.processor.insertSpeakerAt.bind(this.processor);
        window.performSpeakerInsertion = this.processor.performSpeakerInsertion.bind(this.processor);
        window.showNewSpeakerInlineForInsertion = this.processor.showNewSpeakerInlineForInsertion.bind(this.processor);
        window.confirmInlineInsertion = this.processor.confirmInlineInsertion.bind(this.processor);
        window.removeSpeaker = this.processor.removeSpeaker.bind(this.processor);
        window.moveSegment = this.processor.moveSegment.bind(this.processor);
        
        window.renderRedactionList = this.processor.renderRedactionList.bind(this.processor);
        window.removeRedaction = this.processor.removeRedaction.bind(this.processor);
        window.clearAllRedactions = this.processor.clearAllRedactions.bind(this.processor);
        window.redactSelectedText = this.processor.redactSelectedText.bind(this.processor);
        window.toggleRedactionAccordion = this.ui.toggleRedactionAccordion.bind(this.ui);
    }

    initEventListeners() {
        this.ui.initEventListeners();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.app = new TranscriptApp();
    });
} else {
    window.app = new TranscriptApp();
}
